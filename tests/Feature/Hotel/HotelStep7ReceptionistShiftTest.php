<?php

use App\Filament\Pages\ReceptionistShift;
use App\Models\Room;
use App\Models\Shift;
use App\Models\StaffDebt;
use App\Models\User;
use App\Services\BookingService;
use App\Services\FolioService;
use App\Services\ReceptionistShiftService;
use App\Services\ReservationService;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Step 7 of the hotel module: receptionist shift float/settlement. Mirrors
 * ShiftAccountingService's waiter pattern (declare -> pending_supervisor
 * -> supervisor confirms -> variance frozen, shortfall becomes a
 * StaffDebt) as a fully separate service — waiter settlement math itself
 * is never touched. The one real difference: expected cash includes the
 * starting till float, not just what was collected.
 */
it('starts a receptionist shift with a starting float, closing any dangling active shift first', function () {
    $user = User::factory()->create();
    $stale = Shift::create(['user_id' => $user->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    $shift = (new ReceptionistShiftService())->startShift($user, 5000);

    expect($shift->type)->toBe('receptionist');
    expect((float) $shift->starting_float)->toBe(5000.0);
    expect($shift->status)->toBe('active');
    expect($stale->fresh()->status)->toBe('closed');
});

it('declares end of shift, moving it to pending_supervisor', function () {
    $user = User::factory()->create();
    $shift = (new ReceptionistShiftService())->startShift($user, 5000);

    $declared = (new ReceptionistShiftService())->declareEnd($shift, 12000, 3000);

    expect($declared->status)->toBe('pending_supervisor');
    expect((float) $declared->declared_cash)->toBe(12000.0);
    expect((float) $declared->declared_pos)->toBe(3000.0);
    expect($declared->ended_at)->not->toBeNull();
});

it('refuses to declare end for a non-receptionist shift or an inactive one', function () {
    $user = User::factory()->create();
    $waiterShift = Shift::create(['user_id' => $user->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    expect(fn () => (new ReceptionistShiftService())->declareEnd($waiterShift, 100, 0))->toThrow(Exception::class);

    $shift = (new ReceptionistShiftService())->startShift($user, 0);
    (new ReceptionistShiftService())->declareEnd($shift, 100, 0);
    expect(fn () => (new ReceptionistShiftService())->declareEnd($shift->fresh(), 100, 0))->toThrow(Exception::class);
});

it('includes the starting float in expected cash, on top of cash folio payments made during the shift', function () {
    $receptionist = User::factory()->create();
    $shift = (new ReceptionistShiftService())->startShift($receptionist, 5000);

    $room = Room::create(['number' => '701', 'type' => 'Standard', 'price_per_night' => 15000, 'status' => 'available', 'housekeeping' => 'clean']);
    $booking = (new ReservationService())->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Shift Guest', 'guest_phone' => '0806' . fake()->numerify('#######'),
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => 2000,
    ], $receptionist->id);

    // A cash payment made through the folio screen, also during this shift.
    (new FolioService())->recordPayment($booking->folio, 3000, 'cash', null, $receptionist->id);
    // A transfer payment must NOT count toward cash.
    (new FolioService())->recordPayment($booking->folio, 1500, 'transfer', 'REF-1', $receptionist->id);

    $expectedCash = (new ReceptionistShiftService())->expectedCashRemittance($shift->fresh());
    // 5000 float + 2000 deposit (cash by default) + 3000 cash payment = 10000
    expect($expectedCash)->toBe(10000.0);

    $expectedPos = (new ReceptionistShiftService())->expectedPosTotal($shift->fresh());
    expect($expectedPos)->toBe(1500.0);
});

it('does not attribute a payment to a shift when the recording user has no active shift', function () {
    $receptionist = User::factory()->create(); // no shift started
    $room = Room::create(['number' => '702', 'type' => 'Standard', 'price_per_night' => 15000, 'status' => 'available', 'housekeeping' => 'clean']);
    $booking = (new ReservationService())->createReservation([
        'room_id' => $room->id, 'guest_name' => 'No Shift Guest', 'guest_phone' => '0807' . fake()->numerify('#######'),
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => null,
    ], $receptionist->id);

    $line = (new FolioService())->recordPayment($booking->folio, 1000, 'cash', null, $receptionist->id);

    expect($line->shift_id)->toBeNull();
});

it('closes the shift with no debt when confirmed cash matches expected', function () {
    $receptionist = User::factory()->create();
    $manager = User::factory()->create();
    $shift = (new ReceptionistShiftService())->startShift($receptionist, 5000);
    (new ReceptionistShiftService())->declareEnd($shift, 5000, 0);

    $debt = (new ReceptionistShiftService())->applyShiftSettlement($shift->fresh(), $manager, 5000, 0, null);

    expect($debt)->toBeNull();
    expect($shift->fresh()->status)->toBe('closed');
    expect((float) $shift->fresh()->cash_variance)->toBe(0.0);
});

it('creates a reception_shortfall StaffDebt when confirmed cash falls short of expected', function () {
    $receptionist = User::factory()->create();
    $manager = User::factory()->create();
    $shift = (new ReceptionistShiftService())->startShift($receptionist, 5000);
    (new ReceptionistShiftService())->declareEnd($shift, 4000, 0); // short by 1000

    $debt = (new ReceptionistShiftService())->applyShiftSettlement($shift->fresh(), $manager, 4000, 0, 'counted short');

    expect($debt)->not->toBeNull();
    expect($debt->reason)->toBe('reception_shortfall');
    expect((float) $debt->amount)->toBe(1000.0);
    expect($debt->user_id)->toBe($receptionist->id);
    expect(StaffDebt::where('reason', 'reception_shortfall')->count())->toBe(1);
});

it('records a surplus without creating a debt', function () {
    $receptionist = User::factory()->create();
    $manager = User::factory()->create();
    $shift = (new ReceptionistShiftService())->startShift($receptionist, 5000);
    (new ReceptionistShiftService())->declareEnd($shift, 5500, 0);

    $debt = (new ReceptionistShiftService())->applyShiftSettlement($shift->fresh(), $manager, 5500, 0, null);

    expect($debt)->toBeNull();
    expect((float) $shift->fresh()->surplus_amount)->toBe(500.0);
});

it('refuses to settle a shift that is not pending supervisor review', function () {
    $receptionist = User::factory()->create();
    $manager = User::factory()->create();
    $shift = (new ReceptionistShiftService())->startShift($receptionist, 0);

    expect(fn () => (new ReceptionistShiftService())->applyShiftSettlement($shift, $manager, 0, 0, null))->toThrow(Exception::class);
});

it('refuses to end a receptionist shift through the generic waiter-style endShift() control', function () {
    $receptionist = User::factory()->create();
    (new ReceptionistShiftService())->startShift($receptionist, 5000);

    expect(fn () => $receptionist->endShift())->toThrow(Exception::class);
});

it('maps a receptionist role to a receptionist-typed shift via the generic startShift()', function () {
    $receptionist = User::factory()->create();
    $receptionist->assignRole(Role::firstOrCreate(['name' => 'receptionist']));

    $shift = $receptionist->startShift();

    expect($shift->type)->toBe('receptionist');
});

it('drives the receptionist shift page end to end: start with a float, then declare end', function () {
    Artisan::call('db:seed', ['--class' => 'PagePermissionsSeeder', '--force' => true]);
    $receptionist = User::factory()->create();
    $receptionist->assignRole(Role::firstOrCreate(['name' => 'receptionist']));

    $component = Livewire::actingAs($receptionist)->test(ReceptionistShift::class);
    $component->set('startingFloat', 4000);
    $component->call('startShift');

    $shift = $receptionist->fresh()->shifts()->where('status', 'active')->first();
    expect($shift)->not->toBeNull();
    expect((float) $shift->starting_float)->toBe(4000.0);

    $component->set('declaredCash', 4000);
    $component->set('declaredPos', 0);
    $component->call('declareEnd');

    expect($shift->fresh()->status)->toBe('pending_supervisor');
});
