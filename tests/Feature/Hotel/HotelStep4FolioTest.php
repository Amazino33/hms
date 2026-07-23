<?php

use App\Filament\Pages\TransferVerification;
use App\Models\FolioLine;
use App\Models\Room;
use App\Models\User;
use App\Services\BookingService;
use App\Services\FolioService;
use App\Services\ReservationService;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Step 4 of the hotel module: the folio screen (incidental charges,
 * payments) and the manager transfer-verification queue. Folio lines are
 * immutable — every assertion here checks that corrections are appended,
 * never that an existing line changes value.
 */
function makeCheckedInBooking(string $roomNumber = '401', float $rate = 15000): array
{
    $room = Room::create(['number' => $roomNumber, 'type' => 'Standard', 'price_per_night' => $rate, 'status' => 'available', 'housekeeping' => 'clean']);
    $user = User::factory()->create();
    $booking = (new ReservationService)->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Folio Guest', 'guest_phone' => '0803'.fake()->numerify('#######'),
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => null,
    ], $user->id);
    $booking = (new BookingService)->checkIn($booking, $user->id);

    return [$booking, $user];
}

it('posts an incidental charge to the folio, increasing the balance', function () {
    [$booking, $user] = makeCheckedInBooking();
    $startBalance = $booking->folio->balance();

    (new FolioService)->postIncidental($booking->folio, 'Extra towel', 500, $user->id);

    expect($booking->folio->fresh()->balance())->toBe($startBalance + 500);
});

it('rejects an incidental charge of zero or less', function () {
    [$booking, $user] = makeCheckedInBooking();

    expect(fn () => (new FolioService)->postIncidental($booking->folio, 'Free thing', 0, $user->id))->toThrow(Exception::class);
});

it('records a cash payment as immediately verified, reducing the balance', function () {
    [$booking, $user] = makeCheckedInBooking();
    $startBalance = $booking->folio->balance();

    $line = (new FolioService)->recordPayment($booking->folio, 5000, 'cash', null, $user->id);

    expect($line->verified)->toBeTrue();
    expect($booking->folio->fresh()->balance())->toBe($startBalance - 5000);
});

it('records a transfer payment as unverified until a manager confirms it', function () {
    [$booking, $user] = makeCheckedInBooking();

    $line = (new FolioService)->recordPayment($booking->folio, 5000, 'transfer', 'GTB-REF-123', $user->id);

    expect($line->verified)->toBeFalse();
    expect($line->reference)->toBe('GTB-REF-123');
});

it('verifying a transfer payment stamps verified_by/at and does not change its amount', function () {
    [$booking, $user] = makeCheckedInBooking();
    $manager = User::factory()->create();

    $line = (new FolioService)->recordPayment($booking->folio, 5000, 'transfer', 'REF-1', $user->id);
    $originalAmount = (float) $line->amount;

    $verified = (new FolioService)->verifyTransfer($line, $manager->id);

    expect($verified->verified)->toBeTrue();
    expect($verified->verified_by)->toBe($manager->id);
    expect((float) $verified->amount)->toBe($originalAmount);
});

it('rejecting a transfer payment appends a reversal charge and clears the pending queue without editing the original amount', function () {
    [$booking, $user] = makeCheckedInBooking();
    $manager = User::factory()->create();
    $balanceBeforePayment = $booking->folio->balance();

    $line = (new FolioService)->recordPayment($booking->folio, 5000, 'transfer', 'REF-2', $user->id);
    $originalAmount = (float) $line->amount;

    (new FolioService)->rejectTransfer($line, 'No matching bank alert', $manager->id);

    expect((float) $line->fresh()->amount)->toBe($originalAmount);
    expect($line->fresh()->verified)->toBeTrue();

    // The reversal neutralizes the fraudulent/failed payment; balance ends
    // up exactly where it was before the (fake) payment was recorded.
    expect($booking->folio->fresh()->balance())->toBe($balanceBeforePayment);

    expect(FolioLine::where('type', 'payment')->where('payment_method', 'transfer')->where('verified', false)->count())->toBe(0);
});

it('refuses to verify or reject an already-resolved transfer line', function () {
    [$booking, $user] = makeCheckedInBooking();
    $manager = User::factory()->create();

    $line = (new FolioService)->recordPayment($booking->folio, 5000, 'transfer', 'REF-3', $user->id);
    (new FolioService)->verifyTransfer($line, $manager->id);

    expect(fn () => (new FolioService)->verifyTransfer($line->fresh(), $manager->id))->toThrow(Exception::class);
    expect(fn () => (new FolioService)->rejectTransfer($line->fresh(), 'too late', $manager->id))->toThrow(Exception::class);
});

it('refuses to verify or reject a non-transfer payment line', function () {
    [$booking, $user] = makeCheckedInBooking();
    $manager = User::factory()->create();

    $cashLine = (new FolioService)->recordPayment($booking->folio, 5000, 'cash', null, $user->id);

    expect(fn () => (new FolioService)->verifyTransfer($cashLine, $manager->id))->toThrow(Exception::class);
});

it('shows only unresolved transfer payments in the manager verification queue', function () {
    [$booking, $user] = makeCheckedInBooking('402');
    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'PagePermissionsSeeder', '--force' => true]);
    $manager = User::factory()->create();
    $manager->assignRole(Role::firstOrCreate(['name' => 'manager']));

    $pending = (new FolioService)->recordPayment($booking->folio, 3000, 'transfer', 'REF-PENDING', $user->id);
    $resolved = (new FolioService)->recordPayment($booking->folio, 2000, 'transfer', 'REF-RESOLVED', $user->id);
    (new FolioService)->verifyTransfer($resolved, $manager->id);
    (new FolioService)->recordPayment($booking->folio, 1000, 'cash', null, $user->id);

    $component = Livewire::actingAs($manager)->test(TransferVerification::class);
    $lineIds = collect($component->instance()->getViewData()['lines'])->pluck('id');

    expect($lineIds)->toContain($pending->id);
    expect($lineIds)->not->toContain($resolved->id);
});

it('lets a manager reject a transfer through the real page component', function () {
    [$booking, $user] = makeCheckedInBooking('403');
    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'PagePermissionsSeeder', '--force' => true]);
    $manager = User::factory()->create();
    $manager->assignRole(Role::firstOrCreate(['name' => 'manager']));

    $line = (new FolioService)->recordPayment($booking->folio, 4000, 'transfer', 'REF-REJECT', $user->id);

    $component = Livewire::actingAs($manager)->test(TransferVerification::class);
    $component->call('openReject', $line->id);
    $component->set('rejectReason', 'Never received');
    $component->call('reject');

    expect($line->fresh()->verified)->toBeTrue();
    expect($line->fresh()->reference)->toContain('Rejected');
});

it('renders the folio detail page for a valid booking via a real HTTP request', function () {
    [$booking, $user] = makeCheckedInBooking('404');
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));

    $response = $this->actingAs($admin)->get('/admin/folio?booking='.$booking->id);

    $response->assertOk();
    $response->assertSee($booking->guest->name);
});

it('applies a discount against the room charge, reducing the balance', function () {
    [$booking, $user] = makeCheckedInBooking('501', 15000);
    $startBalance = $booking->folio->balance();

    $line = (new FolioService)->applyDiscount($booking->folio, 5000, 'Loyalty guest', $user->id);

    expect($line->type)->toBe('discount');
    expect((float) $line->amount)->toBe(-5000.0);
    expect($booking->folio->fresh()->balance())->toBe($startBalance - 5000);
});

it('rejects a discount of zero or less', function () {
    [$booking, $user] = makeCheckedInBooking('502', 15000);

    expect(fn () => (new FolioService)->applyDiscount($booking->folio, 0, 'No reason', $user->id))->toThrow(Exception::class);
});

it('rejects a discount with no reason given', function () {
    [$booking, $user] = makeCheckedInBooking('503', 15000);

    expect(fn () => (new FolioService)->applyDiscount($booking->folio, 1000, '', $user->id))->toThrow(Exception::class);
});

it('refuses a discount larger than the room charge', function () {
    [$booking, $user] = makeCheckedInBooking('504', 15000);

    expect(fn () => (new FolioService)->applyDiscount($booking->folio, 15001, 'Too generous', $user->id))->toThrow(Exception::class);
});

it('refuses a second discount that would push the total past the room charge', function () {
    [$booking, $user] = makeCheckedInBooking('505', 15000);

    (new FolioService)->applyDiscount($booking->folio, 10000, 'First discount', $user->id);

    expect(fn () => (new FolioService)->applyDiscount($booking->folio->fresh(), 6000, 'Second discount', $user->id))
        ->toThrow(Exception::class);

    // The room is still left with exactly 5000 discountable — this succeeds.
    $line = (new FolioService)->applyDiscount($booking->folio->fresh(), 5000, 'Remaining amount', $user->id);
    expect((float) $line->amount)->toBe(-5000.0);
});

it('never lets a discount touch an incidental charge, only the room charge', function () {
    [$booking, $user] = makeCheckedInBooking('506', 15000);
    (new FolioService)->postIncidental($booking->folio, 'Room service', 8000, $user->id);

    // Room charge alone is 15000, so a discount up to 15000 is still fine
    // even though the incidental charge makes the total balance 23000.
    $line = (new FolioService)->applyDiscount($booking->folio->fresh(), 15000, 'Full room comp', $user->id);
    expect((float) $line->amount)->toBe(-15000.0);

    // But one more naira should fail, since the room charge is now fully used up.
    expect(fn () => (new FolioService)->applyDiscount($booking->folio->fresh(), 1, 'One more', $user->id))
        ->toThrow(Exception::class);
});

it('refuses to apply a discount once the folio is sealed', function () {
    [$booking, $user] = makeCheckedInBooking('507', 15000);
    (new FolioService)->recordPayment($booking->folio, 15000, 'cash', null, $user->id);
    (new BookingService)->checkOut($booking, $user->id);

    expect(fn () => (new FolioService)->applyDiscount($booking->folio->fresh(), 1000, 'Too late', $user->id))
        ->toThrow(Exception::class);
});

it('applies a discount through the real folio page component', function () {
    // Filament Page components with mount(Request $request) don't mount
    // cleanly through Livewire::test()'s snapshot protocol outside a real
    // panel request (same reason SettlementDetail's own tests drive the
    // page directly instead) — mount() is called by hand here too.
    [$booking, $user] = makeCheckedInBooking('508', 15000);
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));
    auth()->login($admin);

    $page = new \App\Filament\Pages\FolioDetail;
    $page->mount(\Illuminate\Http\Request::create('/admin/folio', 'GET', ['booking' => $booking->id]));
    $page->discountReason = 'Manager comp';
    $page->discountAmount = 3000;
    $page->applyDiscount();

    expect($booking->folio->fresh()->lines()->where('type', 'discount')->exists())->toBeTrue();
    expect($booking->folio->fresh()->balance())->toBe(12000.0);
});

it('redirects the folio detail page back to the timeline when no booking id is given', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));

    $response = $this->actingAs($admin)->get('/admin/folio');

    $response->assertRedirect('/admin/reservations-timeline');
});
