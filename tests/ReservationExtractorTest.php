<?php

declare(strict_types=1);

use LeKlube\Reservation;
use LeKlube\ReservationExtractor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Extraction des séances depuis les props du tableau de bord, sans réseau.
 */
final class ReservationExtractorTest extends TestCase
{
    private string $soon;

    protected function setUp(): void
    {
        $this->soon = new DateTimeImmutable('+2 days')->format('Y-m-d');
    }

    public function testReadsReservationFields(): void
    {
        [$reservation] = $this->extract($this->aquaWork());

        $this->assertSame('AQUA WORK', $reservation->title);
        $this->assertSame('STUDIO AQUA', $reservation->location);
        $this->assertSame(45, $reservation->durationMinutes);
        $this->assertSame(Reservation::STATUS_BOOKED, $reservation->status);
    }

    public function testUsesShortenedClassEventIdRatherThanAttendeeId(): void
    {
        [$reservation] = $this->extract($this->aquaWork());

        $this->assertSame('class_events:26495755', $reservation->id);
    }

    public function testCombinesBareStartTimeWithDate(): void
    {
        [$reservation] = $this->extract($this->aquaWork());

        $this->assertSame("$this->soon 12:30", $reservation->startAt->format('Y-m-d H:i'));
        $this->assertSame('13:15', $reservation->endAt()->format('H:i'));
    }

    public function testRecognizesWaitingList(): void
    {
        [$reservation] = $this->extract($this->pilatesOnWaitingList());

        $this->assertTrue($reservation->isWaitingList());
    }

    public function testFallsBackToDefaultLocationAndDuration(): void
    {
        [$reservation] = $this->extract($this->pilatesOnWaitingList());

        $this->assertSame('Le Klube', $reservation->location);
        $this->assertSame(45, $reservation->durationMinutes);
    }

    public function testSortsReservationsChronologically(): void
    {
        $reservations = $this->extract($this->aquaWork(), $this->pilatesOnWaitingList());

        $this->assertSame(['PILATES', 'AQUA WORK'], array_column($reservations, 'title'));
    }

    public function testIgnoresPastReservation(): void
    {
        $past = new DateTimeImmutable('-2 days')->format('Y-m-d');

        $this->assertSame([], $this->extract(['date' => $past] + $this->aquaWork()));
    }

    public function testIgnoresCancelledReservation(): void
    {
        $this->assertSame([], $this->extract(['status' => 'cancelled'] + $this->aquaWork()));
    }

    public function testReturnsNothingWithoutReservations(): void
    {
        $extractor = new ReservationExtractor(new NullLogger());

        $this->assertSame([], $extractor->extractUpcoming([]));
        $this->assertSame([], $extractor->extractUpcoming(['upcomingReservations' => []]));
    }

    /**
     * @return list<Reservation>
     */
    private function extract(array ...$items): array
    {
        return new ReservationExtractor(new NullLogger())->extractUpcoming(['upcomingReservations' => $items]);
    }

    /** Réservation complète, de la forme réellement renvoyée par Le Klube. */
    private function aquaWork(): array
    {
        return [
            'id' => '/leklube/attendees/119807239',
            'classEventId' => '/leklube/class_events/26495755',
            'title' => 'AQUA WORK',
            'location' => 'STUDIO AQUA',
            'date' => $this->soon,
            'startAt' => '12:30',
            'durationMinutes' => 45,
            'status' => 'booked',
            'type' => 'collective-course',
        ];
    }

    /** Liste d'attente, sans durée ni salle : les deux replis doivent s'appliquer. */
    private function pilatesOnWaitingList(): array
    {
        return [
            'id' => '/leklube/attendees/2',
            'classEventId' => '/leklube/class_events/2',
            'title' => 'PILATES',
            'date' => $this->soon,
            'startAt' => '08:00',
            'status' => 'on-waiting-list',
            'type' => 'collective-course',
        ];
    }
}
