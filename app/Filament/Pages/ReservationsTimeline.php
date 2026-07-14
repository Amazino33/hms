<?php

namespace App\Filament\Pages;

use App\Models\Booking;
use App\Models\Room;
use App\Services\BookingService;
use App\Services\PermissionService;
use App\Services\ReservationService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use UnitEnum;

/**
 * 14-day room-availability Gantt. Bars are positioned with plain CSS Grid
 * (grid-column: start / span N) rather than a JS charting library — the
 * math lives in computeBarPosition() as a pure function precisely so the
 * off-by-one-prone start/span arithmetic can be unit tested directly,
 * instead of trusted by eye in the rendered grid.
 */
class ReservationsTimeline extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|UnitEnum|null $navigationGroup = 'Hotel';

    protected static ?string $navigationLabel = 'Reservations Timeline';

    protected static ?string $title = 'Reservations Timeline';

    protected string $view = 'filament.pages.reservations-timeline';

    public const WINDOW_DAYS = 14;

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    public bool $showForm = false;

    public ?int $selectedRoomId = null;

    public ?string $selectedRoomNumber = null;

    public string $guestName = '';

    public string $guestPhone = '';

    public string $checkIn = '';

    public string $checkOut = '';

    public ?float $deposit = null;

    public bool $showDetails = false;

    public ?int $selectedBookingId = null;

    public function openDetails(int $bookingId): void
    {
        $this->selectedBookingId = $bookingId;
        $this->showDetails = true;
    }

    public function closeDetails(): void
    {
        $this->showDetails = false;
        $this->selectedBookingId = null;
    }

    public function checkInSelected(): void
    {
        if (! $this->selectedBookingId) {
            return;
        }

        try {
            $booking = Booking::findOrFail($this->selectedBookingId);
            (new BookingService())->checkIn($booking, auth()->id());

            $this->closeDetails();

            Notification::make()
                ->title('Guest checked in')
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Could not check in')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function openForm(int $roomId, string $date): void
    {
        $room = Room::find($roomId);

        $this->selectedRoomId = $roomId;
        $this->selectedRoomNumber = $room?->number;
        $this->checkIn = $date;
        $this->checkOut = Carbon::parse($date)->addDay()->toDateString();
        $this->guestName = '';
        $this->guestPhone = '';
        $this->deposit = null;
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
    }

    public function submit(): void
    {
        try {
            (new ReservationService())->createReservation([
                'room_id' => $this->selectedRoomId,
                'guest_name' => $this->guestName,
                'guest_phone' => $this->guestPhone ?: null,
                'check_in' => $this->checkIn,
                'check_out' => $this->checkOut,
                'deposit' => $this->deposit,
            ], auth()->id());

            $this->showForm = false;

            Notification::make()
                ->title('Reservation created')
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Could not create reservation')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Clamps a booking's date range to the visible window and returns its
     * 0-based day offset + column span, or null if it doesn't actually
     * intersect the window. check_out is treated as exclusive (the
     * checkout day itself isn't a booked night), matching how the rest of
     * the hotel module already treats the range.
     *
     * @return array{start: int, span: int}|null
     */
    public static function computeBarPosition(Carbon $windowStart, int $windowDays, Carbon $checkIn, Carbon $checkOut): ?array
    {
        $windowStart = $windowStart->copy()->startOfDay();
        $checkIn = $checkIn->copy()->startOfDay();
        $checkOut = $checkOut->copy()->startOfDay();
        $windowEndExclusive = $windowStart->copy()->addDays($windowDays);

        $visibleStart = $checkIn->greaterThan($windowStart) ? $checkIn->copy() : $windowStart->copy();
        $visibleEndExclusive = $checkOut->lessThan($windowEndExclusive) ? $checkOut->copy() : $windowEndExclusive->copy();

        if ($visibleEndExclusive->lessThanOrEqualTo($visibleStart)) {
            return null;
        }

        return [
            'start' => (int) $windowStart->diffInDays($visibleStart),
            'span' => max(1, (int) $visibleStart->diffInDays($visibleEndExclusive)),
        ];
    }

    public function selectedBooking(): ?Booking
    {
        if (! $this->selectedBookingId) {
            return null;
        }

        return Booking::with(['guest', 'room', 'folio.lines'])->find($this->selectedBookingId);
    }

    public function getViewData(): array
    {
        $windowStart = Carbon::today();
        $windowEnd = $windowStart->copy()->addDays(self::WINDOW_DAYS - 1);

        $days = collect(range(0, self::WINDOW_DAYS - 1))
            ->map(fn (int $i) => $windowStart->copy()->addDays($i));

        $rooms = Room::orderBy('number')->get();

        $bookingsByRoom = \App\Models\Booking::whereNotIn('status', ['cancelled'])
            ->where('check_in', '<', $windowEnd->copy()->addDay()->toDateString())
            ->where('check_out', '>', $windowStart->toDateString())
            ->with('guest')
            ->get()
            ->groupBy('room_id');

        $bars = [];

        foreach ($rooms as $room) {
            $bars[$room->id] = $bookingsByRoom->get($room->id, collect())
                ->map(function ($booking) use ($windowStart) {
                    $position = self::computeBarPosition($windowStart, self::WINDOW_DAYS, $booking->check_in, $booking->check_out);

                    if ($position === null) {
                        return null;
                    }

                    return [
                        'booking_id' => $booking->id,
                        'guest_name' => $booking->guest?->name ?? 'Guest',
                        'status' => $booking->status,
                        'start' => $position['start'],
                        'span' => $position['span'],
                    ];
                })
                ->filter()
                ->values();
        }

        return [
            'rooms' => $rooms,
            'days' => $days,
            'bars' => $bars,
        ];
    }
}
