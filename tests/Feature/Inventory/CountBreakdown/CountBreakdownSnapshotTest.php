<?php

use App\Exceptions\AppendOnlyViolation;
use App\Models\CountBreakdown;
use App\Models\CountBreakdownLine;
use App\Models\CountBreakdownMovement;
use App\Models\CountOpenOrder;
use App\Models\CountSession;
use App\Models\CountVarianceNote;
use App\Models\HandoverDiscrepancy;
use App\Services\CountBreakdownSnapshotService;
use App\Services\CountBreakdownViewService;
use App\Services\CountSessionService;

it('works out every figure from transfers, sales, voids, damages and returns in the window', function () {
    $c = cbFullScenario(25);
    $line = $c['line'];

    expect((float) $line->brought_forward)->toBe(12.0)
        ->and((float) $line->transferred_in)->toBe(24.0)
        ->and((float) $line->returns_in)->toBe(1.0)
        ->and((float) $line->other_in)->toBe(0.0)
        ->and((float) $line->available)->toBe(37.0)
        ->and((float) $line->sold_qty)->toBe(9.0)
        ->and((float) $line->sales_amount)->toBe(9000.0)
        ->and((float) $line->damages_writeoffs)->toBe(1.0)
        ->and((float) $line->other_out)->toBe(0.0)
        ->and((float) $line->unrecorded_change)->toBe(0.0)
        ->and((float) $line->expected_remaining)->toBe(27.0)
        ->and((float) $line->counted)->toBe(25.0)
        ->and((float) $line->variance_qty)->toBe(-2.0)
        ->and((float) $line->variance_value_selling)->toBe(-2000.0)
        ->and((float) $line->variance_value_cost)->toBe(-1200.0)
        ->and($line->pack_unit_name)->toBe('crate')
        ->and($line->units_per_pack)->toBe(24);

    // The breakdown explains the sealed figures; it never disagrees with them.
    $item = $line->sessionItem;
    expect((float) $item->adjusted_expected_quantity)->toBe(27.0)
        ->and((float) $item->variance)->toBe(-2.0);
});

it('makes every figure on every line equal the sum of its active snapshot movements', function () {
    $c = cbFullScenario(25);

    $lines = CountBreakdownLine::with('movements')->get();
    expect($lines)->not->toBeEmpty();

    foreach ($lines as $line) {
        foreach (CountBreakdownLine::FIGURE_COLUMNS as $figure => $column) {
            $sum = round((float) $line->movements->where('figure', $figure)->where('status', 'active')->sum('quantity'), 2);

            expect($sum)->toBe(round((float) $line->{$column}, 2), "{$figure} on line {$line->id}");
        }

        $soldAmount = round((float) $line->movements->where('figure', 'sold')->where('status', 'active')->sum('amount'), 2);
        expect($soldAmount)->toBe(round((float) $line->sales_amount, 2));
    }
});

it('records who sold each sale, when it was placed and when marked ready, and flags the transfer and damage people', function () {
    $c = cbFullScenario(25);
    $movements = $c['line']->movements;

    $slow = $movements->where('figure', 'sold')->firstWhere('order_id', $c['saleSlow']->id);
    expect($slow->waiter_name)->toBe('Staff One')
        ->and((float) $slow->quantity)->toBe(3.0)
        ->and((float) $slow->amount)->toBe(3000.0)
        ->and($slow->placed_at)->not->toBeNull()
        ->and($slow->ready_at)->not->toBeNull()
        ->and((int) $slow->placed_at->diffInMinutes($slow->ready_at))->toBe(47);

    $transfer = $movements->firstWhere('figure', 'transferred');
    expect($transfer->sender_name)->toBe('Store Keeper')
        ->and($transfer->receiver_name)->toBe('Bola Bartender')
        ->and($transfer->sent_at)->not->toBeNull()
        ->and($transfer->received_at)->not->toBeNull();

    $damage = $movements->firstWhere('figure', 'damages');
    expect($damage->reason)->toBe('Bottle dropped')
        ->and($damage->recorder_name)->toBe('Bola Bartender')
        ->and($damage->approver_name)->toBe('Manager Mo');

    $return = $movements->firstWhere('figure', 'returns');
    expect($return->label)->toBe('Return confirmed')
        ->and($return->waiter_name)->toBe('Staff Two')
        ->and($return->recorder_name)->toBe('Bola Bartender')
        ->and($return->reason)->toBe('Guest changed mind');

    $bf = $movements->firstWhere('figure', 'brought_forward');
    expect((int) $bf->source_id)->toBe($c['previous']->id)
        ->and($bf->recorder_name)->toBe('Ada Bartender');
});

it('stores a voided sale with who voided it and when, but leaves it out of sold', function () {
    $c = cbFullScenario(25);

    $voided = $c['line']->movements->where('figure', 'sold')->firstWhere('order_id', $c['saleVoided']->id);

    expect($voided->status)->toBe('voided')
        ->and((float) $voided->quantity)->toBe(2.0)
        ->and($voided->voided_by_name)->toBe('Manager Mo')
        ->and($voided->voided_at)->not->toBeNull();

    // Its restock is folded into the crossed-out sale, not counted as a return.
    expect($c['line']->movements->where('figure', 'returns')->where('order_id', $c['saleVoided']->id))->toBeEmpty();
    expect((float) $c['line']->sold_qty)->toBe(9.0);
});

it('excludes movements before the previous seal and after this seal', function () {
    \Illuminate\Support\Carbon::setTestNow(now()->startOfMinute());
    $c = cbSetup(30);

    $before = cbSale($c['product'], $c['waiterOne'], 4); // absorbed by the previous count
    cbTick();
    $previous = cbSeal($c['bar'], $c['bartenderA'], $c['pinA'], $c['bartenderB'], $c['pinB']);
    cbTick();
    $inside = cbSale($c['product'], $c['waiterTwo'], 5);
    cbTick();
    $session = cbSeal($c['bar'], $c['bartenderB'], $c['pinB'], $c['bartenderA'], $c['pinA']);
    cbTick();
    $after = cbSale($c['product'], $c['waiterOne'], 7);

    $line = CountBreakdownLine::where('count_session_id', $session->id)->first();
    $orderIds = $line->movements()->where('figure', 'sold')->pluck('order_id')->all();

    expect($orderIds)->toBe([$inside->id])
        ->and((float) $line->brought_forward)->toBe(26.0)
        ->and((float) $line->sold_qty)->toBe(5.0)
        ->and((float) $line->expected_remaining)->toBe(21.0);
});

it('does not change stored variance naira when prices change after sealing', function () {
    $c = cbFullScenario(25);
    $c['product']->update(['price' => 5000, 'last_cost_price' => 4000]);

    $line = $c['line']->fresh();
    expect((float) $line->unit_selling_price)->toBe(1000.0)
        ->and((float) $line->variance_value_selling)->toBe(-2000.0)
        ->and((float) $line->variance_value_cost)->toBe(-1200.0);

    $payload = (new CountBreakdownViewService)->payload($c['session']->fresh(), true);
    expect($payload['sections'][0]['lines'][0]['value'])->toBe(-2000.0)
        ->and($payload['sections'][0]['lines'][0]['value_cost'])->toBe(-1200.0);
});

it('does not change the snapshot when a sale in the window is voided after sealing', function () {
    $c = cbFullScenario(25);
    $before = $c['line']->movements()->get()->toArray();

    test()->actingAs($c['manager']);
    $c['saleQuick']->fresh()->update(['status' => 'cancelled']);

    $line = $c['line']->fresh();
    expect((float) $line->sold_qty)->toBe(9.0)
        ->and($line->movements()->where('order_id', $c['saleQuick']->id)->value('status'))->toBe('active')
        ->and($line->movements()->get()->toArray())->toBe($before);
});

it('refuses to update or delete snapshot lines, movements, open orders and notes', function () {
    $c = cbFullScenario(25);
    $open = CountOpenOrder::create(['count_session_id' => $c['session']->id, 'order_id' => 1, 'item_name' => 'X', 'quantity' => 1]);
    $note = CountVarianceNote::create(['count_breakdown_line_id' => $c['line']->id, 'user_id' => $c['bartenderB']->id, 'author_name' => 'Bola', 'body' => 'Spilled']);

    $records = [
        $c['line']->fresh(),
        $c['line']->movements()->first(),
        $open,
        $note,
        CountBreakdown::first(),
    ];

    foreach ($records as $record) {
        expect(fn () => $record->update(['created_at' => now()->addDay()]))->toThrow(AppendOnlyViolation::class);
        expect(fn () => $record->delete())->toThrow(AppendOnlyViolation::class);
        expect($record->fresh())->not->toBeNull();
    }
});

it('writes the snapshot in the same transaction as the seal, so a failed seal writes none of it', function () {
    \Illuminate\Support\Carbon::setTestNow(now()->startOfMinute());
    $c = cbSetup(10);

    app()->bind(CountBreakdownSnapshotService::class, fn () => new class extends CountBreakdownSnapshotService
    {
        public function capture(CountSession $session): CountBreakdown
        {
            parent::capture($session); // writes everything, then the lock fails

            throw new RuntimeException('Simulated failure after the snapshot was written');
        }
    });

    expect(fn () => cbSeal($c['bar'], $c['bartenderA'], $c['pinA'], $c['bartenderB'], $c['pinB'], [$c['product']->id => 7]))
        ->toThrow(RuntimeException::class);

    $session = CountSession::first();
    expect($session->status)->toBe('declared')
        ->and(CountBreakdown::count())->toBe(0)
        ->and(CountBreakdownLine::count())->toBe(0)
        ->and(CountBreakdownMovement::count())->toBe(0)
        ->and(HandoverDiscrepancy::count())->toBe(0)
        ->and((float) \App\Models\InventoryItem::where('warehouse_id', $c['bar']->id)->value('quantity'))->toBe(10.0);
});

it('snapshots exactly once per sealed count, with one line per counted item', function () {
    $c = cbFullScenario(25);

    expect(CountBreakdown::where('count_session_id', $c['session']->id)->count())->toBe(1)
        ->and(CountBreakdownLine::where('count_session_id', $c['session']->id)->count())->toBe($c['session']->items()->count());
});

it('records stock that moved without a ledger row as an unrecorded change, so the figures still add up', function () {
    \Illuminate\Support\Carbon::setTestNow(now()->startOfMinute());
    $c = cbSetup(10);
    cbSeal($c['bar'], $c['bartenderA'], $c['pinA'], $c['bartenderB'], $c['pinB']);
    cbTick();

    // A direct stock write with no transaction behind it.
    \App\Models\InventoryItem::where('warehouse_id', $c['bar']->id)->update(['quantity' => 13]);
    cbTick();

    $session = cbSeal($c['bar'], $c['bartenderB'], $c['pinB'], $c['bartenderA'], $c['pinA']);
    $line = CountBreakdownLine::where('count_session_id', $session->id)->first();

    expect((float) $line->unrecorded_change)->toBe(3.0)
        ->and((float) $line->expected_remaining)->toBe(13.0)
        ->and((float) $line->variance_qty)->toBe(0.0)
        ->and((float) $line->movements()->where('figure', 'unrecorded')->where('status', 'active')->sum('quantity'))->toBe(3.0);
});

it('also snapshots the closing-count path at submit, and shows it only once finalized', function () {
    \Illuminate\Support\Carbon::setTestNow(now()->startOfMinute());
    $c = cbSetup(10);
    $service = new CountSessionService;

    $session = $service->openSession('bar_handover', $c['bar']->id, $c['bartenderA']->id, $c['bartenderA']->id, $c['bartenderB']->id, isClosing: true);
    $service->recordCount($session->items()->first(), ['Fridge' => 8]);
    $service->confirmOutgoing($session, $c['bartenderA']->id);
    $service->confirmIncoming($session->fresh(), $c['bartenderB']->id);
    $session = $service->submitForReview($session->fresh());

    expect($session->breakdown)->not->toBeNull()
        ->and(CountBreakdownViewService::canView($session, $c['bartenderA']))->toBeFalse();

    $this->actingAs($c['bartenderA'])->get(route('count-breakdown.pdf', $session->id))->assertForbidden();

    $service->reviewItem($session->items()->first(), $c['manager']->id, 'true_up');
    $session = $service->finalizeReview($session->fresh(), $c['manager']->id);

    expect(CountBreakdownViewService::canView($session, $c['bartenderA']))->toBeTrue();
    $line = $session->breakdown->lines()->first();
    expect((float) $line->variance_qty)->toBe(-2.0)
        ->and((float) $line->variance_value_selling)->toBe(-2000.0);
});
