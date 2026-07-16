<?php

use App\Filament\Pages\FolioDetail;
use App\Models\Room;
use App\Models\User;
use App\Services\BookingService;
use App\Services\FolioService;
use App\Services\ReservationService;
use Illuminate\Http\Request;

/**
 * Part of the system-wide notification/silent-failure fix: pins that the
 * "folio balance must be zero" checkout guard (BookingService::checkOut())
 * reaches the user as a persistent danger notification through the real
 * page, and that a blocked checkout never mutates the booking.
 */
function makeCheckedInBookingForCheckoutNotification(string $roomNumber, float $rate = 15000): array
{
    $room = Room::create(['number' => $roomNumber, 'type' => 'Standard', 'price_per_night' => $rate, 'status' => 'available', 'housekeeping' => 'clean']);
    $user = User::factory()->create();
    $booking = (new ReservationService())->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Checkout Notif Guest', 'guest_phone' => '0803' . fake()->numerify('#######'),
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => null,
    ], $user->id);
    $booking = (new BookingService())->checkIn($booking, $user->id);

    return [$booking, $user];
}

/**
 * Filament Page components with mount(Request $request) don't mount
 * cleanly through Livewire::test()'s snapshot protocol outside a real
 * panel request (matching the established pattern in
 * tests/Feature/Pos/ServedWorkflowTest.php), so the page's action method
 * is driven directly here instead.
 */
function loadFolioDetailPageForNotification(User $actingAs, int $bookingId): FolioDetail
{
    auth()->login($actingAs);

    $page = new FolioDetail();
    $page->mount(Request::create('/admin/folio', 'GET', ['booking' => $bookingId]));

    return $page;
}

it('blocks checkout while the folio balance is still positive, sending a persistent danger notification and leaving the booking checked in', function () {
    [$booking, $user] = makeCheckedInBookingForCheckoutNotification('601');

    session()->forget('filament.notifications');

    $page = loadFolioDetailPageForNotification($user, $booking->id);
    $page->checkOut();

    $last = collect(session('filament.notifications', []))->last();

    expect($last)->not->toBeNull();
    expect($last['status'])->toBe('danger');
    expect($last['duration'])->toBe('persistent');
    expect($last['title'])->toBe('Could not check out');
    expect($last['body'])->toContain('Folio balance must be zero');

    expect($booking->fresh()->status)->toBe('checked_in');
    expect($booking->fresh()->checked_out_at)->toBeNull();
});

it('checks out with a success notification once the folio balance is fully settled', function () {
    [$booking, $user] = makeCheckedInBookingForCheckoutNotification('602');
    (new FolioService())->recordPayment($booking->folio, (float) $booking->total_price, 'cash', null, $user->id);

    session()->forget('filament.notifications');

    $page = loadFolioDetailPageForNotification($user, $booking->id);
    $page->checkOut();

    $last = collect(session('filament.notifications', []))->last();

    expect($last)->not->toBeNull();
    expect($last['status'])->toBe('success');
    expect($last['title'])->toBe('Guest checked out');

    expect($booking->fresh()->status)->toBe('checked_out');
    expect($booking->fresh()->checked_out_at)->not->toBeNull();
});
