<?php

use App\Models\FolioLine;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use App\Services\BookingService;
use App\Services\FolioService;
use App\Services\ReservationService;

/**
 * "Editing" a payment or a discount before checkout is always void-then-
 * repost — the original line never changes, a reversal is appended.
 */
function makeVoidableBooking(string $roomNumber, float $rate = 15000): array
{
    $room = Room::create(['number' => $roomNumber, 'type' => 'Standard', 'price_per_night' => $rate, 'status' => 'available', 'housekeeping' => 'clean']);
    $user = User::factory()->create();
    $booking = (new ReservationService)->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Void Guest', 'guest_phone' => '0803'.fake()->numerify('#######'),
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => null,
    ], $user->id);

    return [(new BookingService)->checkIn($booking, $user->id), $user];
}

it('voids a payment by appending a reversal, netting the balance back', function () {
    [$booking, $user] = makeVoidableBooking('901');
    $balanceBefore = $booking->folio->balance();

    $payment = (new FolioService)->recordPayment($booking->folio, 5000, 'cash', null, $user->id);

    expect($booking->folio->fresh()->balance())->toBe($balanceBefore - 5000);

    $reversal = (new FolioService)->voidLine($payment, 'wrong amount keyed', $user->id);

    expect($reversal->type)->toBe('payment')
        ->and((float) $reversal->amount)->toBe(5000.0)
        ->and($reversal->payment_method)->toBe('cash')
        ->and($reversal->reversal_of_line_id)->toBe($payment->id)
        ->and((float) $payment->fresh()->amount)->toBe(-5000.0)
        ->and($booking->folio->fresh()->balance())->toBe($balanceBefore);
});

it('voids a discount and frees the room charge back up to be discounted again', function () {
    [$booking, $user] = makeVoidableBooking('902', 15000);

    $discount = (new FolioService)->applyDiscount($booking->folio, 15000, 'manager comp', $user->id);

    // Room charge fully eaten — a second discount must be refused.
    expect(fn () => (new FolioService)->applyDiscount($booking->folio, 1000, 'again', $user->id))
        ->toThrow(Exception::class);

    (new FolioService)->voidLine($discount, 'comped the wrong booking', $user->id);

    $corrected = (new FolioService)->applyDiscount($booking->folio, 5000, 'correct comp', $user->id);

    expect((float) $corrected->amount)->toBe(-5000.0);
});

it('refuses to void the same line twice', function () {
    [$booking, $user] = makeVoidableBooking('903');
    $payment = (new FolioService)->recordPayment($booking->folio, 2000, 'cash', null, $user->id);

    (new FolioService)->voidLine($payment, 'mistake', $user->id);

    expect(fn () => (new FolioService)->voidLine($payment->fresh(), 'again', $user->id))
        ->toThrow(Exception::class, 'already been voided');
});

it('refuses to void a reversal line itself', function () {
    [$booking, $user] = makeVoidableBooking('904');
    $payment = (new FolioService)->recordPayment($booking->folio, 2000, 'cash', null, $user->id);
    $reversal = (new FolioService)->voidLine($payment, 'mistake', $user->id);

    expect(fn () => (new FolioService)->voidLine($reversal, 'undo the undo', $user->id))
        ->toThrow(Exception::class, 'itself a void');
});

it('refuses to void anything other than a payment or a discount', function () {
    [$booking, $user] = makeVoidableBooking('905');
    $incidental = (new FolioService)->postIncidental($booking->folio, 'Extra towel', 500, $user->id);

    expect(fn () => (new FolioService)->voidLine($incidental, 'wrong', $user->id))
        ->toThrow(Exception::class, 'Only a payment or a discount');
});

it('requires a reason', function () {
    [$booking, $user] = makeVoidableBooking('906');
    $payment = (new FolioService)->recordPayment($booking->folio, 2000, 'cash', null, $user->id);

    expect(fn () => (new FolioService)->voidLine($payment, '   ', $user->id))
        ->toThrow(Exception::class, 'reason is required');
});

it('refuses to void once the guest has checked out', function () {
    [$booking, $user] = makeVoidableBooking('907');
    $payment = (new FolioService)->recordPayment($booking->folio, 500000, 'cash', null, $user->id);

    (new BookingService)->checkOut($booking->fresh(), $user->id);

    expect(fn () => (new FolioService)->voidLine($payment->fresh(), 'too late', $user->id))
        ->toThrow(Exception::class, 'sealed');
});

it('refuses to void a payment whose shift has already closed', function () {
    [$booking, $user] = makeVoidableBooking('908');

    $shift = Shift::create([
        'user_id' => $user->id,
        'type' => 'receptionist',
        'started_at' => now()->subHour(),
        'status' => 'active',
    ]);

    $payment = (new FolioService)->recordPayment($booking->folio, 3000, 'cash', null, $user->id);
    $payment->update(['shift_id' => $shift->id]);

    $shift->update(['ended_at' => now(), 'status' => 'closed']);

    expect(fn () => (new FolioService)->voidLine($payment->fresh(), 'wrong amount', $user->id))
        ->toThrow(Exception::class, 'already been closed');
});

it('clears a voided unverified transfer out of the verification queue', function () {
    [$booking, $user] = makeVoidableBooking('909');

    $transfer = (new FolioService)->recordPayment($booking->folio, 4000, 'transfer', 'REF123', $user->id);

    expect($transfer->verified)->toBeFalse();

    (new FolioService)->voidLine($transfer, 'guest sent it to the wrong account', $user->id);

    expect($transfer->fresh()->verified)->toBeTrue()
        ->and(FolioLine::where('type', 'payment')->where('payment_method', 'transfer')->where('verified', false)->count())->toBe(0);
});
