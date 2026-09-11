<?php

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use App\Services\StaffReportService;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Role;

/**
 * "My History" and the staff cash widget both grouped on
 * created_at->format('Y-m-d') — a UTC calendar day. The venue trades past
 * midnight, so a 1am sale landed in the following day's row: it dropped
 * off the figure the waiter or bartender was actually being measured on
 * that night, and reappeared attached to a shift they had already handed
 * over.
 *
 * Both now key on BusinessDay (9am WAT to 9am WAT), the same boundary
 * every owner/CEO report already counts by, so a night's trading stays in
 * one row and the screens agree with each other.
 */
function makeWaiterForBusinessDay(): User
{
    $waiter = User::factory()->create();
    $waiter->assignRole(Role::firstOrCreate(['name' => 'waiter']));

    return $waiter;
}

/**
 * An order stamped at a precise UTC instant. created_at has to be forced
 * after creation — Eloquent stamps it with now() on insert.
 */
function orderAt(User $waiter, string $utc, float $amount, string $destination = 'bar'): Order
{
    $order = Order::create([
        'order_number' => 'ORD-'.uniqid(),
        'user_id' => $waiter->id,
        'destination' => $destination,
        'status' => 'paid',
        'total_amount' => $amount,
        'amount_paid' => $amount,
    ]);

    $at = CarbonImmutable::parse($utc, 'UTC');
    $order->forceFill(['created_at' => $at])->save();

    OrderPayment::create([
        'order_id' => $order->id, 'amount' => $amount, 'method' => 'cash',
        'user_id' => $waiter->id, 'paid_at' => $at,
    ]);

    return $order->fresh();
}

it('files a 1am sale under the night it belongs to, not the next calendar day', function () {
    $waiter = makeWaiterForBusinessDay();

    // 00:30 UTC on the 12th is 01:30 Lagos on the 12th — still the 11th's
    // trading night, because the business day does not close until 9am.
    orderAt($waiter, '2026-07-12 00:30:00', 4000);

    $history = (new StaffReportService)->staffDailyHistory($waiter->id, '2026-07-11', '2026-07-11');

    expect(array_keys($history))->toBe(['2026-07-11'])
        ->and((float) $history['2026-07-11']['payments_total'])->toBe(4000.0);
});

it('starts a new row once trading passes 9am', function () {
    $waiter = makeWaiterForBusinessDay();

    orderAt($waiter, '2026-07-12 00:30:00', 4000);  // 01:30 Lagos -> 11th
    orderAt($waiter, '2026-07-12 09:30:00', 1500);  // 10:30 Lagos -> 12th

    $history = (new StaffReportService)->staffDailyHistory($waiter->id, '2026-07-11', '2026-07-12');

    expect((float) $history['2026-07-11']['payments_total'])->toBe(4000.0)
        ->and((float) $history['2026-07-12']['payments_total'])->toBe(1500.0);
});

/**
 * BusinessDay::boundsFor() ends a day at exactly the next one's 9am, so an
 * inclusive BETWEEN would count a 9am-sharp order twice — once at the end
 * of one day and again at the start of the next.
 */
it('counts a sale landing exactly on the 9am boundary in one day only', function () {
    $waiter = makeWaiterForBusinessDay();

    // 08:00:00 UTC is 09:00:00 Lagos exactly — the boundary instant.
    orderAt($waiter, '2026-07-12 08:00:00', 2500);

    $previous = (new StaffReportService)->staffDailyHistory($waiter->id, '2026-07-11', '2026-07-11');
    $current = (new StaffReportService)->staffDailyHistory($waiter->id, '2026-07-12', '2026-07-12');

    expect($previous)->toBe([])
        ->and((float) $current['2026-07-12']['payments_total'])->toBe(2500.0);
});

it('keeps a whole trading night in one row across midnight', function () {
    $waiter = makeWaiterForBusinessDay();

    orderAt($waiter, '2026-07-11 21:00:00', 1000);  // 22:00 Lagos, 11th
    orderAt($waiter, '2026-07-11 23:30:00', 2000);  // 00:30 Lagos, 12th
    orderAt($waiter, '2026-07-12 02:00:00', 3000);  // 03:00 Lagos, 12th

    $history = (new StaffReportService)->staffDailyHistory($waiter->id, '2026-07-11', '2026-07-11');

    expect(array_keys($history))->toBe(['2026-07-11'])
        ->and($history['2026-07-11']['orders'])->toHaveCount(3)
        ->and((float) $history['2026-07-11']['payments_total'])->toBe(6000.0);
});

it('counts bar cash for the trading night rather than the calendar day', function () {
    $waiter = makeWaiterForBusinessDay();

    orderAt($waiter, '2026-07-12 00:30:00', 5000, 'bar');   // 01:30 Lagos -> 11th
    orderAt($waiter, '2026-07-12 09:30:00', 800, 'bar');    // 10:30 Lagos -> 12th

    $night = (new StaffReportService)->expectedCashByDestination('bar', '2026-07-11', '2026-07-11');
    $nextDay = (new StaffReportService)->expectedCashByDestination('bar', '2026-07-12', '2026-07-12');

    expect((float) $night['collected'])->toBe(5000.0)
        ->and((float) $nextDay['collected'])->toBe(800.0);
});

it('does not mix another destination into a destination total', function () {
    $waiter = makeWaiterForBusinessDay();

    orderAt($waiter, '2026-07-12 00:30:00', 5000, 'bar');
    orderAt($waiter, '2026-07-12 00:45:00', 900, 'kitchen');

    $bar = (new StaffReportService)->expectedCashByDestination('bar', '2026-07-11', '2026-07-11');
    $kitchen = (new StaffReportService)->expectedCashByDestination('kitchen', '2026-07-11', '2026-07-11');

    expect((float) $bar['collected'])->toBe(5000.0)
        ->and((float) $kitchen['collected'])->toBe(900.0);
});

it('defaults to the current business day when no range is given', function () {
    $waiter = makeWaiterForBusinessDay();

    [$start] = BusinessDay::boundsFor(BusinessDay::today());
    orderAt($waiter, $start->addHour()->toDateTimeString(), 1200, 'bar');

    $data = (new StaffReportService)->expectedCashByDestination('bar');

    expect((float) $data['collected'])->toBe(1200.0);
});
