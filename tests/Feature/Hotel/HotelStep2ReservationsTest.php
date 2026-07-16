<?php

use App\Filament\Pages\ReservationsTimeline;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Room;
use App\Models\User;
use App\Services\ReservationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * Step 2 of the hotel module: reservation creation (with concurrency-safe
 * double-booking prevention and rate snapshotting), the auto-release job
 * for no-deposit same-day reservations, and the timeline's bar-positioning
 * math.
 */
function makeRoom(string $number = '201', float $rate = 15000): Room
{
    return Room::create(['number' => $number, 'type' => 'Standard', 'price_per_night' => $rate, 'status' => 'available', 'housekeeping' => 'clean']);
}

it('creates a reservation with a folio and snapshots the room rate', function () {
    $room = makeRoom(rate: 15000);
    $user = User::factory()->create();

    $booking = (new ReservationService())->createReservation([
        'room_id' => $room->id,
        'guest_name' => 'Jane Doe',
        'guest_phone' => '08010000001',
        'check_in' => now()->toDateString(),
        'check_out' => now()->addDays(2)->toDateString(),
        'deposit' => null,
    ], $user->id);

    expect($booking->status)->toBe('reserved');
    expect((float) $booking->nightly_rate)->toBe(15000.0);
    expect((float) $booking->total_price)->toBe(30000.0);
    expect($booking->created_by_user_id)->toBe($user->id);
    expect($booking->folio)->not->toBeNull();
    expect($booking->folio->balance())->toBe(0.0);

    // Changing the room's price afterwards must never retroactively change
    // an already-created booking's rate.
    $room->update(['price_per_night' => 99999]);
    expect((float) $booking->fresh()->nightly_rate)->toBe(15000.0);
});

it('creates a payment folio line for a deposit at reservation time', function () {
    $room = makeRoom();
    $user = User::factory()->create();

    $booking = (new ReservationService())->createReservation([
        'room_id' => $room->id,
        'guest_name' => 'Deposit Guest',
        'guest_phone' => '08010000002',
        'check_in' => now()->toDateString(),
        'check_out' => now()->addDay()->toDateString(),
        'deposit' => 5000,
    ], $user->id);

    expect((float) $booking->deposit)->toBe(5000.0);
    expect($booking->folio->lines()->count())->toBe(1);

    $line = $booking->folio->lines()->first();
    expect($line->type)->toBe('payment');
    expect((float) $line->amount)->toBe(-5000.0);
    expect($booking->folio->balance())->toBe(-5000.0);
});

it('resolves an existing guest by phone instead of duplicating', function () {
    $room1 = makeRoom('202');
    $room2 = makeRoom('203');
    $existing = Guest::create(['name' => 'Repeat Guest', 'phone' => '08010000003']);

    $service = new ReservationService();
    $booking1 = $service->createReservation([
        'room_id' => $room1->id, 'guest_name' => 'Repeat Guest', 'guest_phone' => '08010000003',
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => null,
    ], null);
    $booking2 = $service->createReservation([
        'room_id' => $room2->id, 'guest_name' => 'Repeat Guest', 'guest_phone' => '08010000003',
        'check_in' => now()->addDays(5)->toDateString(), 'check_out' => now()->addDays(6)->toDateString(), 'deposit' => null,
    ], null);

    expect(Guest::where('phone', '08010000003')->count())->toBe(1);
    expect($booking1->guest_id)->toBe($existing->id);
    expect($booking2->guest_id)->toBe($existing->id);
});

it('rejects a genuinely overlapping reservation for the same room', function () {
    $room = makeRoom();
    $service = new ReservationService();

    $service->createReservation([
        'room_id' => $room->id, 'guest_name' => 'First Guest', 'guest_phone' => '08010000004',
        'check_in' => now()->addDays(3)->toDateString(), 'check_out' => now()->addDays(6)->toDateString(), 'deposit' => null,
    ], null);

    expect(fn () => $service->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Second Guest', 'guest_phone' => '08010000005',
        'check_in' => now()->addDays(4)->toDateString(), 'check_out' => now()->addDays(5)->toDateString(), 'deposit' => null,
    ], null))->toThrow(Exception::class);
});

it('accepts an adjacent, non-overlapping reservation for the same room', function () {
    $room = makeRoom();
    $service = new ReservationService();

    $service->createReservation([
        'room_id' => $room->id, 'guest_name' => 'First Guest', 'guest_phone' => '08010000006',
        'check_in' => now()->addDays(3)->toDateString(), 'check_out' => now()->addDays(6)->toDateString(), 'deposit' => null,
    ], null);

    $second = $service->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Second Guest', 'guest_phone' => '08010000007',
        'check_in' => now()->addDays(6)->toDateString(), 'check_out' => now()->addDays(8)->toDateString(), 'deposit' => null,
    ], null);

    expect($second->id)->not->toBeNull();
});

it('does not let a cancelled or no_show booking block a new reservation for the same dates', function () {
    $room = makeRoom();
    $service = new ReservationService();

    $cancelled = $service->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Cancelled Guest', 'guest_phone' => '08010000008',
        'check_in' => now()->addDays(3)->toDateString(), 'check_out' => now()->addDays(5)->toDateString(), 'deposit' => null,
    ], null);
    $service->cancelReservation($cancelled, 'guest called off', User::factory()->create()->id);

    $noShow = $service->createReservation([
        'room_id' => $room->id, 'guest_name' => 'No Show Guest', 'guest_phone' => '08010000009',
        'check_in' => now()->addDays(10)->toDateString(), 'check_out' => now()->addDays(12)->toDateString(), 'deposit' => null,
    ], null);
    $noShow->update(['status' => 'no_show']);

    $rebooked = $service->createReservation([
        'room_id' => $room->id, 'guest_name' => 'New Guest', 'guest_phone' => '08010000010',
        'check_in' => now()->addDays(3)->toDateString(), 'check_out' => now()->addDays(5)->toDateString(), 'deposit' => null,
    ], null);

    expect($rebooked->id)->not->toBeNull();
});

it('rejects a check-out that is not after check-in', function () {
    $room = makeRoom();

    expect(fn () => (new ReservationService())->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Bad Dates', 'guest_phone' => '08010000011',
        'check_in' => now()->addDays(3)->toDateString(), 'check_out' => now()->addDays(3)->toDateString(), 'deposit' => null,
    ], null))->toThrow(Exception::class);
});

it('rejects cancelling an already checked-out or cancelled booking', function () {
    $room = makeRoom();
    $service = new ReservationService();
    $booking = $service->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Guest', 'guest_phone' => '08010000012',
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => null,
    ], null);
    $user = User::factory()->create();

    $service->cancelReservation($booking, 'changed mind', $user->id);

    expect(fn () => $service->cancelReservation($booking->fresh(), 'again', $user->id))->toThrow(Exception::class);
});

it('auto-releases a same-day no-deposit reservation only once the release hour has passed', function () {
    config(['hms.reservation_auto_release_hour' => 18]);
    $room = makeRoom();
    $booking = (new ReservationService())->createReservation([
        'room_id' => $room->id, 'guest_name' => 'No Deposit Guest', 'guest_phone' => '08010000013',
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => null,
    ], null);

    Carbon::setTestNow(now()->setTime(10, 0));
    Artisan::call('hms:auto-release-reservations');
    expect($booking->fresh()->status)->toBe('reserved');

    Carbon::setTestNow(now()->setTime(19, 0));
    Artisan::call('hms:auto-release-reservations');
    expect($booking->fresh()->status)->toBe('no_show');

    Carbon::setTestNow();
});

it('never auto-releases a deposit-backed reservation', function () {
    config(['hms.reservation_auto_release_hour' => 18]);
    $room = makeRoom();
    $user = User::factory()->create();
    $booking = (new ReservationService())->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Deposit Guest', 'guest_phone' => '08010000014',
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => 2000,
    ], $user->id);

    Carbon::setTestNow(now()->setTime(20, 0));
    Artisan::call('hms:auto-release-reservations');
    expect($booking->fresh()->status)->toBe('reserved');

    Carbon::setTestNow();
});

it('never auto-releases a reservation for a different date', function () {
    config(['hms.reservation_auto_release_hour' => 18]);
    $room = makeRoom();
    $booking = (new ReservationService())->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Future Guest', 'guest_phone' => '08010000015',
        'check_in' => now()->addDays(2)->toDateString(), 'check_out' => now()->addDays(3)->toDateString(), 'deposit' => null,
    ], null);

    Carbon::setTestNow(now()->setTime(20, 0));
    Artisan::call('hms:auto-release-reservations');
    expect($booking->fresh()->status)->toBe('reserved');

    Carbon::setTestNow();
});

it('computes the bar start/span for a booking fully inside the visible window', function () {
    $windowStart = Carbon::parse('2026-07-14');
    $position = ReservationsTimeline::computeBarPosition($windowStart, 14, Carbon::parse('2026-07-14'), Carbon::parse('2026-07-16'));

    expect($position)->toBe(['start' => 0, 'span' => 2]);
});

it('clips the bar start for a booking that began before the visible window', function () {
    $windowStart = Carbon::parse('2026-07-14');
    $position = ReservationsTimeline::computeBarPosition($windowStart, 14, Carbon::parse('2026-07-10'), Carbon::parse('2026-07-16'));

    expect($position)->toBe(['start' => 0, 'span' => 2]);
});

it('clips the bar span for a booking that extends past the visible window', function () {
    $windowStart = Carbon::parse('2026-07-14');
    $position = ReservationsTimeline::computeBarPosition($windowStart, 14, Carbon::parse('2026-07-25'), Carbon::parse('2026-07-30'));

    // Window is [07-14 .. 07-27], windowEndExclusive = 07-28.
    // start = diff(07-14, 07-25) = 11, span = diff(07-25, 07-28) = 3.
    expect($position)->toBe(['start' => 11, 'span' => 3]);
});

it('returns null for a booking that does not intersect the visible window at all', function () {
    $windowStart = Carbon::parse('2026-07-14');
    $position = ReservationsTimeline::computeBarPosition($windowStart, 14, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-05'));

    expect($position)->toBeNull();
});

it('grants receptionist access to the reservations timeline page', function () {
    Artisan::call('db:seed', ['--class' => 'PagePermissionsSeeder', '--force' => true]);

    $receptionist = User::factory()->create();
    $receptionist->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'receptionist']));

    $this->actingAs($receptionist);
    expect(\App\Services\PermissionService::canAccessPage(ReservationsTimeline::class))->toBeTrue();
});

/**
 * Mobile pass: phone-width gets a day-at-a-time room list instead of the
 * 14-day Gantt (which is mathematically unfittable at 360px). These pin the
 * navigation bounds — same $days/$bars data the Gantt already computes, just
 * a different offset into it.
 */
it('clamps day navigation to the visible window, never going negative or past the last day', function () {
    Artisan::call('db:seed', ['--class' => 'PagePermissionsSeeder', '--force' => true]);
    $receptionist = User::factory()->create();
    $receptionist->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'receptionist']));

    $component = \Livewire\Livewire::actingAs($receptionist)->test(ReservationsTimeline::class);

    expect($component->instance()->selectedDayOffset)->toBe(0);

    $component->call('prevDay');
    expect($component->instance()->selectedDayOffset)->toBe(0); // clamped, not negative

    $component->call('jumpToDay', 13);
    expect($component->instance()->selectedDayOffset)->toBe(13);

    $component->call('nextDay');
    expect($component->instance()->selectedDayOffset)->toBe(13); // clamped to the last day

    $component->call('jumpToDay', 999);
    expect($component->instance()->selectedDayOffset)->toBe(13); // clamped even for an out-of-range jump

    $component->call('prevDay');
    expect($component->instance()->selectedDayOffset)->toBe(12);
});

it('renders the day-list room row as vacant-and-tappable-to-reserve when nothing books that room on the selected day', function () {
    Artisan::call('db:seed', ['--class' => 'PagePermissionsSeeder', '--force' => true]);
    $receptionist = User::factory()->create();
    $receptionist->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'receptionist']));
    $room = Room::create(['number' => '501', 'type' => 'Standard', 'price_per_night' => 15000, 'status' => 'available', 'housekeeping' => 'clean']);

    $html = \Livewire\Livewire::actingAs($receptionist)->test(ReservationsTimeline::class)->html();

    expect($html)->toContain('Vacant — tap to reserve');
});

it('renders the day-list room row with the guest name and status when a booking covers the selected day', function () {
    Artisan::call('db:seed', ['--class' => 'PagePermissionsSeeder', '--force' => true]);
    $receptionist = User::factory()->create();
    $receptionist->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'receptionist']));
    $room = Room::create(['number' => '502', 'type' => 'Standard', 'price_per_night' => 15000, 'status' => 'available', 'housekeeping' => 'clean']);

    (new ReservationService())->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Timeline Guest', 'guest_phone' => '0809' . fake()->numerify('#######'),
        'check_in' => now()->toDateString(), 'check_out' => now()->addDays(2)->toDateString(), 'deposit' => null,
    ], $receptionist->id);

    $html = \Livewire\Livewire::actingAs($receptionist)->test(ReservationsTimeline::class)->html();

    expect($html)->toContain('Timeline Guest');
    expect($html)->toContain('Reserved');
});
