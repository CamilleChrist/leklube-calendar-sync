<?php

declare(strict_types=1);

namespace LeKlube;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

final class ReservationExtractor
{
    private const array BOOKED = [
        'booked',
        'registered',
        'confirmed',
        'reserved',
        'attending',
        'subscribed'
    ];
    private const array WAITING = [
        'on-waiting-list',
        'waiting-list',
        'waitinglist',
        'waiting',
        'attente'
    ];
    private const int DEFAULT_DURATION = 45;
    private const string DEFAULT_LOCATION = 'Le Klube';

    public function __construct(
        private readonly LoggerInterface $logger
    )
    {}

    public function extractUpcoming(array $props): array
    {
        $items = $props['upcomingReservations'] ?? [];

        if (!is_array($items)) {
            $this->logger->warning('upcomingReservations absent ou invalide');
            return [];
        }

        $now = new DateTimeImmutable('now');
        $reservations = [];

        foreach ($items as $item) {
            if (!is_array($item)) continue;

            $start = $this->resolveStart($item);
            if ($start === null || $start < $now) continue;

            // Annulée ou statut inconnu : pas d'événement, et celui déjà créé sera supprimé comme orphelin.
            $status = $this->resolveStatus($item);
            if ($status === null) continue;

            $location = ($item['location'] ?? '') ?: self::DEFAULT_LOCATION;
            $duration = is_numeric($item['durationMinutes'] ?? null) && (int)($item['durationMinutes']) > 0
                ? (int)$item['durationMinutes']
                : self::DEFAULT_DURATION;

            $reservations[] = new Reservation(
                id: $this->shortenIri($item['classEventId']),
                title: $item['title'],
                location: $location,
                startAt: $start,
                durationMinutes: $duration,
                status: $status,
            );
        }

        usort($reservations, static fn(Reservation $a, Reservation $b) => $a->startAt <=> $b->startAt);

        return $reservations;
    }

    private function resolveStart(array $reservation): ?DateTimeImmutable
    {
        $date = (string)($reservation['date'] ?? '');
        $time = (string)($reservation['startAt'] ?? '');

        try {
            return new DateTimeImmutable("$date $time");
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveStatus(array $reservation): ?string
    {
        $status = strtolower($reservation['status']);

        return match (true) {
            in_array($status, self::WAITING, true) => Reservation::STATUS_WAITING_LIST,
            in_array($status, self::BOOKED, true) => Reservation::STATUS_BOOKED,
            default => null,
        };
    }

    private function shortenIri(string $id): string
    {
        return preg_match('#/([a-z_]+)/(\d+)$#i', $id, $m) === 1 ? "$m[1]:$m[2]" : $id;
    }
}
