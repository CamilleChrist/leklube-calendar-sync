<?php

declare(strict_types=1);

use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use LeKlube\Config;
use LeKlube\GoogleCalendarSync;
use LeKlube\Reservation;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Conversion des séances en événements et choix des suppressions, sans appel à Google.
 */
final class GoogleCalendarSyncTest extends TestCase
{
    private GoogleCalendarSync $sync;

    protected function setUp(): void
    {
        $config = new Config(
            leklubeEmail: null,
            leklubePassword: null,
            googleCredentialsFile: '',
            googleTokenFile: '',
            calendarId: 'primary',
            colorId: '7',
            colorIdWaitlist: '9',
        );

        $this->sync = new GoogleCalendarSync($config, new NullLogger());
    }

    public function testBookedReservationBecomesEvent(): void
    {
        $event = $this->sync->toEvent($this->reservation(Reservation::STATUS_BOOKED));

        $this->assertSame('AQUA WORK', $event->getSummary());
        $this->assertSame('STUDIO AQUA', $event->getLocation());
        $this->assertSame('7', $event->getColorId());
        $this->assertSame('Europe/Paris', $event->getStart()->getTimeZone());
    }

    public function testEventCarriesReservationIdAndSyncMarker(): void
    {
        $event = $this->sync->toEvent($this->reservation(Reservation::STATUS_BOOKED));
        $properties = $event->getExtendedProperties()->getPrivate();

        $this->assertSame('class_events:26495755', $properties['leklubeId']);
        $this->assertSame('1', $properties['leklubeSync']);
    }

    public function testWaitingListEventHasItsOwnColorAndTitle(): void
    {
        $event = $this->sync->toEvent($this->reservation(Reservation::STATUS_WAITING_LIST));

        $this->assertSame('9', $event->getColorId());
        $this->assertStringContainsString("liste d'attente", $event->getSummary());
    }

    public function testUpcomingOrphanIsDeleted(): void
    {
        $this->assertSame(1, $this->deleteOrphans(existing: ['a' => $this->eventStarting('+2 hours')]));
    }

    public function testPastOrphanIsKept(): void
    {
        $this->assertSame(0, $this->deleteOrphans(existing: ['a' => $this->eventStarting('-2 hours')]));
    }

    public function testStillBookedEventIsKept(): void
    {
        $this->assertSame(0, $this->deleteOrphans(
            existing: ['a' => $this->eventStarting('+2 hours')],
            seen: ['a' => true],
        ));
    }

    private function reservation(string $status): Reservation
    {
        return new Reservation(
            id: 'class_events:26495755',
            title: 'AQUA WORK',
            location: 'STUDIO AQUA',
            startAt: new DateTimeImmutable('+2 days 12:30'),
            durationMinutes: 45,
            status: $status,
        );
    }

    private function eventStarting(string $when): Event
    {
        return new Event(['start' => ['dateTime' => new DateTimeImmutable($when)->format(DATE_ATOM)]]);
    }

    /**
     * Appelle la méthode privée en dry-run : elle compte les suppressions sans contacter Google.
     *
     * @param array<string, Event> $existing
     * @param array<string, bool> $seen
     */
    private function deleteOrphans(array $existing, array $seen = []): int
    {
        $service = new Calendar(new Client());

        return new ReflectionMethod($this->sync, 'deleteOrphans')->invoke($this->sync, $service, $existing, $seen, true);
    }
}
