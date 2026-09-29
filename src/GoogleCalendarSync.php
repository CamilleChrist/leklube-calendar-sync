<?php

declare(strict_types=1);

namespace LeKlube;

use DateTimeImmutable;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Exception as GoogleServiceException;
use Psr\Log\LoggerInterface;

/**
 * Transformation des séances Le Klube en événements Google Calendar et synchronisation.
 *
 * Le rapprochement se fait via une propriété privée `leklubeId` posée sur chaque événement,
 * ce qui donne une correspondance exacte (contrairement à une recherche plein texte) et permet
 * de repérer les événements orphelins, c'est-à-dire les séances qui ne sont plus réservées.
 */
final class GoogleCalendarSync
{
    private const string PROP_ID = 'leklubeId';
    private const string PROP_MARKER = 'leklubeSync';

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Synchronise l'ensemble des séances : créations, mises à jour et suppressions.
     *
     * @param list<Reservation> $reservations
     * @return array{created: int, updated: int, unchanged: int, deleted: int}
     */
    public function sync(Calendar $service, array $reservations, bool $dryRun = false): array
    {
        $existing = $this->fetchSyncedEvents($service);
        $this->logger->info(sprintf("%d événement(s) déjà synchronisé(s) dans l'agenda", count($existing)));

        $stats = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'deleted' => 0];
        $seen = [];

        foreach ($reservations as $reservation) {
            $seen[$reservation->id] = true;
            $wanted = $this->toEvent($reservation);
            $current = $existing[$reservation->id] ?? null;

            if ($current === null) {
                $this->logger->info(($dryRun ? '[dry-run] créerait : ' : 'Créé : ') . $reservation->describe());
                if (!$dryRun) {
                    $this->callGoogle(
                        fn () => $service->events->insert($this->config->calendarId, $wanted),
                        "création de « {$reservation->summary()} »",
                    );
                }
                $stats['created']++;
            } elseif ($this->needsUpdate($current, $wanted)) {
                $this->logger->info(($dryRun ? '[dry-run] mettrait à jour : ' : 'Mis à jour : ') . $reservation->describe());
                if (!$dryRun) {
                    $this->callGoogle(
                        fn () => $service->events->update($this->config->calendarId, $current->getId(), $wanted),
                        "mise à jour de « {$reservation->summary()} »",
                    );
                }
                $stats['updated']++;
            } else {
                $this->logger->debug('Inchangé : ' . $reservation->describe());
                $stats['unchanged']++;
            }
        }

        $stats['deleted'] = $this->deleteOrphans($service, $existing, $seen, $dryRun);

        return $stats;
    }

    /**
     * Transforme une séance Le Klube en événement Google Calendar.
     */
    public function toEvent(Reservation $reservation): Event
    {
        return new Event([
            'summary' => $reservation->summary(),
            'description' => $this->buildDescription($reservation),
            'location' => $reservation->location,
            'colorId' => $reservation->isWaitingList() ? $this->config->colorIdWaitlist : $this->config->colorId,
            'start' => $this->eventDateTime($reservation->startAt),
            'end' => $this->eventDateTime($reservation->endAt()),
            'extendedProperties' => [
                'private' => [
                    self::PROP_ID => $reservation->id,
                    self::PROP_MARKER => '1',
                ],
            ],
        ]);
    }

    /**
     * @return array{dateTime: string, timeZone: string}
     */
    private function eventDateTime(DateTimeImmutable $moment): array
    {
        return [
            'dateTime' => $moment->format(DateTimeImmutable::ATOM),
            'timeZone' => date_default_timezone_get(),
        ];
    }

    private function buildDescription(Reservation $reservation): string
    {
        $lines = [];

        if ($reservation->isWaitingList()) {
            $lines[] = "⚠️ Tu es sur liste d'attente : ta place n'est pas garantie.";
        }

        $lines[] = '';
        $lines[] = 'Synchronisé depuis Le Klube — LEKLUBE_ID:' . $reservation->id;

        return implode("\n", $lines);
    }

    /**
     * Récupère les événements déjà posés par cette synchro, indexés par identifiant de séance.
     *
     * @return array<string, Event>
     */
    private function fetchSyncedEvents(Calendar $service): array
    {
        $since = new DateTimeImmutable('-1 day');
        $events = [];
        $pageToken = null;

        do {
            $result = $this->callGoogle(
                fn () => $service->events->listEvents($this->config->calendarId, [
                    'privateExtendedProperty' => self::PROP_MARKER . '=1',
                    'timeMin' => $since->format(DateTimeImmutable::ATOM),
                    'singleEvents' => true,
                    'maxResults' => 250,
                    'pageToken' => $pageToken,
                ]),
                'lecture des événements existants',
            );

            foreach ($result->getItems() as $event) {
                $id = $event->getExtendedProperties()->getPrivate()[self::PROP_ID];
                $events[$id] = $event;
            }

            $pageToken = $result->getNextPageToken();
        } while ($pageToken !== null);

        return $events;
    }

    /**
     * Supprime les événements dont la séance n'apparaît plus comme réservée.
     *
     * Les séances déjà passées sont conservées : leur disparition du compte Le Klube ne doit pas
     * effacer l'historique de l'agenda.
     *
     * @param array<string, Event> $existing
     * @param array<string, bool> $seen
     */
    private function deleteOrphans(Calendar $service, array $existing, array $seen, bool $dryRun): int
    {
        $now = time();
        $deleted = 0;

        foreach ($existing as $id => $event) {
            if (isset($seen[$id])) continue;

            $start = $event->getStart()?->getDateTime();
            if ($start !== null && strtotime($start) < $now) continue;

            $label = sprintf('%s (%s)', $event->getSummary(), $start ?? '?');
            $this->logger->info(($dryRun ? '[dry-run] supprimerait : ' : 'Supprimé : ') . $label);

            if (!$dryRun) {
                $this->callGoogle(
                    fn () => $service->events->delete($this->config->calendarId, $event->getId()),
                    "suppression de « {$event->getSummary()} »",
                );
            }
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Compare l'événement distant à celui qu'on vient de construire, pour éviter les écritures inutiles.
     */
    private function needsUpdate(Event $current, Event $wanted): bool
    {
        return $current->getSummary() !== $wanted->getSummary()
            || $current->getDescription() !== $wanted->getDescription()
            || $current->getLocation() !== $wanted->getLocation()
            || (string) $current->getColorId() !== $wanted->getColorId()
            || !$this->sameMoment($current->getStart()?->getDateTime(), $wanted->getStart()?->getDateTime())
            || !$this->sameMoment($current->getEnd()?->getDateTime(), $wanted->getEnd()?->getDateTime());
    }

    private function sameMoment(?string $a, ?string $b): bool
    {
        if ($a === null || $b === null) return $a === $b;

        return strtotime($a) === strtotime($b);
    }

    /**
     * Exécute un appel à l'API Google en traduisant ses erreurs en SyncException.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function callGoogle(callable $operation, string $context): mixed
    {
        try {
            return $operation();
        } catch (GoogleServiceException $exc) {
            throw new SyncException(
                "Erreur Google Calendar lors de la $context : {$exc->getMessage()}",
                previous: $exc,
            );
        }
    }
}
