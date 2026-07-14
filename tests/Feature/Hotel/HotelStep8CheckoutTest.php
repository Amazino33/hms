<?php

use App\Models\Room;
use App\Models\User;
use App\Services\BookingService;
use App\Services\FolioService;
use App\Services\ReservationService;
use Spatie\Permission\Models\Role;

/**
 * Step 8 of the hotel module: the hard checkout gate (zero folio balance),
 * the sealed checkout snapshot, and the A4 receipt PDF. The snapshot is
 * frozen at checkout time and the PDF always renders from it — never a
 * live folio query — matching HandoverPdfController's established pattern
 * elsewhere in this codebase.
 */
function makeCheckedInBookingForCheckout(string $roomNumber = '801', float $rate = 15000): array
{
    $room = Room::create(['number' => $roomNumber, 'type' => 'Standard', 'price_per_night' => $rate, 'status' => 'available', 'housekeeping' => 'clean']);
    $user = User::factory()->create();
    $booking = (new ReservationService())->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Checkout Guest', 'guest_phone' => '0808' . fake()->numerify('#######'),
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => null,
    ], $user->id);
    $booking = (new BookingService())->checkIn($booking, $user->id);

    return [$booking, $user];
}

it('rejects checkout while the folio balance is still positive', function () {
    [$booking, $user] = makeCheckedInBookingForCheckout();
    // Room charge alone (15000) already leaves a positive balance.

    expect(fn () => (new BookingService())->checkOut($booking, $user->id))->toThrow(Exception::class);
    expect($booking->fresh()->status)->toBe('checked_in');
});

it('checks out once the folio balance is settled to zero, freezing a snapshot', function () {
    [$booking, $user] = makeCheckedInBookingForCheckout(rate: 15000);
    (new FolioService())->recordPayment($booking->folio, 15000, 'cash', null, $user->id);

    $checkedOut = (new BookingService())->checkOut($booking->fresh(), $user->id);

    expect($checkedOut->status)->toBe('checked_out');
    expect($checkedOut->checked_out_at)->not->toBeNull();
    expect($checkedOut->checked_out_by)->toBe($user->id);
    expect($checkedOut->checkout_snapshot)->not->toBeNull();
    expect((float) $checkedOut->checkout_snapshot['balance'])->toBe(0.0);
    expect($checkedOut->checkout_snapshot['guest_name'])->toBe('Checkout Guest');
    expect(count($checkedOut->checkout_snapshot['lines']))->toBe(2); // room charge + payment
});

it('allows checkout when the guest has a credit balance (overpayment)', function () {
    [$booking, $user] = makeCheckedInBookingForCheckout(rate: 15000);
    (new FolioService())->recordPayment($booking->folio, 16000, 'cash', null, $user->id);

    $checkedOut = (new BookingService())->checkOut($booking->fresh(), $user->id);

    expect($checkedOut->status)->toBe('checked_out');
    expect((float) $checkedOut->checkout_snapshot['balance'])->toBe(-1000.0);
});

it('refuses to check out a booking that is not currently checked in', function () {
    $room = Room::create(['number' => '802', 'type' => 'Standard', 'price_per_night' => 15000, 'status' => 'available', 'housekeeping' => 'clean']);
    $user = User::factory()->create();
    $reserved = (new ReservationService())->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Not Checked In', 'guest_phone' => '0809' . fake()->numerify('#######'),
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => null,
    ], $user->id);

    expect(fn () => (new BookingService())->checkOut($reserved, $user->id))->toThrow(Exception::class);
});

it('refuses to check out an already checked-out booking', function () {
    [$booking, $user] = makeCheckedInBookingForCheckout(rate: 15000);
    (new FolioService())->recordPayment($booking->folio, 15000, 'cash', null, $user->id);
    $checkedOut = (new BookingService())->checkOut($booking->fresh(), $user->id);

    expect(fn () => (new BookingService())->checkOut($checkedOut, $user->id))->toThrow(Exception::class);
});

it('seals the folio: no new incidental charge or payment can be posted after checkout', function () {
    [$booking, $user] = makeCheckedInBookingForCheckout(rate: 15000);
    (new FolioService())->recordPayment($booking->folio, 15000, 'cash', null, $user->id);
    (new BookingService())->checkOut($booking->fresh(), $user->id);

    $folio = $booking->folio->fresh();

    expect(fn () => (new FolioService())->postIncidental($folio, 'Late charge', 500, $user->id))->toThrow(Exception::class);
    expect(fn () => (new FolioService())->recordPayment($folio, 100, 'cash', null, $user->id))->toThrow(Exception::class);
});

it('still allows verifying or rejecting an existing transfer payment after checkout', function () {
    [$booking, $user] = makeCheckedInBookingForCheckout(rate: 15000);
    $manager = User::factory()->create();
    (new FolioService())->recordPayment($booking->folio, 15000, 'transfer', 'REF-CHECKOUT', $user->id);
    // Balance is zero (room charge 15000 - transfer 15000), even though unverified.
    $checkedOut = (new BookingService())->checkOut($booking->fresh(), $user->id);

    $line = $checkedOut->folio->fresh()->lines()->where('payment_method', 'transfer')->first();
    $verified = (new FolioService())->verifyTransfer($line, $manager->id);

    expect($verified->verified)->toBeTrue();
});

it('downloads a folio receipt PDF only once checked out', function () {
    [$booking, $user] = makeCheckedInBookingForCheckout(rate: 15000);
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));

    $notYet = $this->actingAs($admin)->get('/folio/' . $booking->id . '/pdf');
    $notYet->assertNotFound();

    (new FolioService())->recordPayment($booking->folio, 15000, 'cash', null, $user->id);
    $checkedOut = (new BookingService())->checkOut($booking->fresh(), $user->id);

    $response = $this->actingAs($admin)->get('/folio/' . $checkedOut->id . '/pdf');
    $response->assertOk();
    $response->assertHeader('content-type', 'application/pdf');
});

it('shows the sealed notice on the real folio detail page once checked out', function () {
    [$booking, $user] = makeCheckedInBookingForCheckout('803', 15000);
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));
    (new FolioService())->recordPayment($booking->folio, 15000, 'cash', null, $user->id);

    $response = $this->actingAs($admin)->get('/admin/folio?booking=' . $booking->id);
    $response->assertOk();

    $checkedOut = (new BookingService())->checkOut($booking->fresh(), $user->id);
    expect($checkedOut->status)->toBe('checked_out');

    $afterResponse = $this->actingAs($admin)->get('/admin/folio?booking=' . $booking->id);
    $afterResponse->assertOk();
    $afterResponse->assertSee('sealed');
});
