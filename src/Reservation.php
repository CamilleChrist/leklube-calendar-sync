<?php

declare(strict_types=1);

namespace LeKlube;

use DateTimeImmutable;

/**
 * Une séance de sport réservée, normalisée depuis la réponse de Le Klube.
 */
final class Reservation
{
    public const string STATUS_BOOKED = 'booked';
    public const string STATUS_WAITING_LIST = 'on-waiting-list';

    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly ?string $location,
        public readonly DateTimeImmutable $startAt,
        public readonly int $durationMinutes,
        public readonly string $status,
    ) {
    }

    public function endAt(): DateTimeImmutable
    {
        return $this->startAt->modify("+{$this->durationMinutes} minutes");
    }

    public function isWaitingList(): bool
    {
        return $this->status === self::STATUS_WAITING_LIST;
    }

    /**
     * Titre affiché dans Google Agenda : la liste d'attente est explicitement signalée.
     */
    public function summary(): string
    {
        return $this->isWaitingList()
            ? "⏳ {$this->title} (liste d'attente)"
            : $this->title;
    }

    public function describe(): string
    {
        return sprintf(
            '%s — %s%s',
            $this->startAt->format('d/m/Y H:i'),
            $this->summary(),
            $this->location !== null ? " @ {$this->location}" : '',
        );
    }
}
