<?php

use App\Exceptions\AppendOnlyViolation;
use App\Filament\Pages\CountSessionDetail;
use App\Models\CountBreakdown;
use App\Models\CountBreakdownLine;
use App\Models\CountOpenOrder;
use App\Models\HandoverDiscrepancy;
use App\Models\PagePermission;
use App\Models\User;
use App\Services\CountBreakdownSettings;
use App\Services\CountBreakdownViewService;
use App\Services\CountSessionService;
use App\Services\CountVarianceNoteService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function cbGrantDetailPage(string $role = 'bartender'): void
{
    PagePermission::firstOrCreate(['page_class' => CountSessionDetail::class, 'role_name' => $role], ['page_name' => 'Count Session Detail']);
}

/** First line of the latest breakdown's payload. */
function cbPayloadLine(\App\Models\CountSession $session, bool $audit = true, ?User $viewer = null): array
{
    return (new CountBreakdownViewService)->payload($session->fresh(), $audit, $viewer)['sections'][0]['lines'][0];
}

it('never puts expected, sold, transferred, damages or variance figures in the count-entry payload', function () {
    \Illuminate\Support\Carbon::setTestNow(now()->startOfMinute());
    $c = cbSetup(731);
    cbGrantDetailPage();
    cbSeal($c['bar'], $c['bartenderA'], $c['pinA'], $c['bartenderB'], $c['pinB']);
    cbTick();
    cbSale($c['product'], $c['waiterOne'], 19);

    $service = new CountSessionService;
    $session = $service->openSession('bar_handover', $c['bar']->id, $c['bartenderB']->id, $c['bartenderB']->id, $c['bartenderA']->id);

    foreach (['counting', 'declared'] as $stage) {
        if ($stage === 'declared') {
            $service->recordCount($session->items()->first(), ['Fridge' => 700], $c['bartenderB']->id);
            $session = $service->declare($session->fresh(), $c['pinB'], 'blind-'.uniqid());
        }

        $component = Livewire::actingAs($c['bartenderB'])->test(CountSessionDetail::class, ['session_id' => $session->id]);
        $snapshot = json_encode($component->getData());
        $html = $component->html();

        expect($component->instance()->breakdownPayload())->toBeNull();

        // The live stock the count is measured against. Checked on the
        // snapshot data only: the HTML carries random hashes that could
        // contain any run of digits.
        expect($snapshot)->not->toContain('712');

        foreach ([$snapshot, $html] as $haystack) {
            expect($haystack)->not->toContain('brought_forward')
                ->not->toContain('sold_qty')
                ->not->toContain('transferred_in')
                ->not->toContain('damages_writeoffs')
                ->not->toContain('variance_value')
                ->not->toContain('Count Summary');
        }
    }
});

it('refuses the breakdown and its PDF/CSV before the count is sealed', function () {
    $c = cbSetup(10);
    $service = new CountSessionService;
    $session = $service->openSession('bar_handover', $c['bar']->id, $c['bartenderA']->id, $c['bartenderA']->id, $c['bartenderB']->id);

    expect(CountBreakdownViewService::canView($session, $c['bartenderA']))->toBeFalse();
    $this->actingAs($c['bartenderA'])->get(route('count-breakdown.pdf', $session->id))->assertForbidden();
    $this->actingAs($c['manager'])->get(route('count-breakdown.pdf', $session->id))->assertForbidden();
    $this->actingAs($c['manager'])->get(route('count-breakdown.csv', $session->id))->assertForbidden();

    $service->recordCount($session->items()->first(), ['Fridge' => 10], $c['bartenderA']->id);
    $session = $service->declare($session, $c['pinA'], 'k-'.uniqid());

    $this->actingAs($c['bartenderA'])->get(route('count-breakdown.pdf', $session->id))->assertForbidden();
    $this->actingAs($c['manager'])->get(route('count-breakdown.csv', $session->id))->assertForbidden();
    expect(CountBreakdown::count())->toBe(0);
});

it('shows a sealed count only to its own participants and to managers', function () {
    $c = cbFullScenario(25);
    cbGrantDetailPage();
    $stranger = User::factory()->create(['name' => 'Other Bartender']);
    $stranger->assignRole('bartender');

    $this->actingAs($stranger)->get(route('count-breakdown.pdf', $c['session']->id))->assertForbidden();
    $this->actingAs($c['bartenderA'])->get(route('count-breakdown.pdf', $c['session']->id))->assertOk();

    $strangerPage = Livewire::actingAs($stranger)->test(CountSessionDetail::class, ['session_id' => $c['session']->id]);
    expect($strangerPage->instance()->canViewResults())->toBeFalse()
        ->and($strangerPage->instance()->breakdownPayload())->toBeNull();
    $strangerPage->assertDontSee('Count Summary')->assertDontSee('Star Lager')->assertSee('only shown to the staff who took part');

    Livewire::actingAs($c['bartenderA'])->test(CountSessionDetail::class, ['session_id' => $c['session']->id])
        ->assertSee('Count Summary')
        ->assertSee('Star Lager');
});

it('never sends cost price to staff, but does to an auditor', function () {
    $c = cbFullScenario(25);
    cbGrantDetailPage();

    $staff = (new CountBreakdownViewService)->payload($c['session'], false, $c['bartenderA']);
    $staffJson = json_encode($staff);
    expect($staffJson)->not->toContain('unit_cost')->not->toContain('value_cost')->not->toContain('variance_value_cost');

    $staffHtml = Livewire::actingAs($c['bartenderA'])->test(CountSessionDetail::class, ['session_id' => $c['session']->id])->html();
    expect($staffHtml)->not->toContain('unit_cost')->not->toContain('value_cost');

    $audit = cbPayloadLine($c['session']);
    expect($audit['unit_cost'])->toBe(600.0)->and($audit['value_cost'])->toBe(-1200.0);

    $managerHtml = Livewire::actingAs($c['manager'])->test(CountSessionDetail::class, ['session_id' => $c['session']->id])->html();
    expect($managerHtml)->toContain('value_cost')->toContain('Count Breakdown');
});

it('freezes orders still open at handover, and leaves out ones already marked ready', function () {
    \Illuminate\Support\Carbon::setTestNow(now()->startOfMinute());
    $c = cbSetup(40);
    cbSeal($c['bar'], $c['bartenderA'], $c['pinA'], $c['bartenderB'], $c['pinB']);
    cbTick();

    $openAtSeal = cbSale($c['product'], $c['waiterOne'], 2);
    $markedReady = cbSale($c['product'], $c['waiterTwo'], 3);
    $markedReady->update(['status' => 'ready']);
    cbTick();

    $session = cbSeal($c['bar'], $c['bartenderB'], $c['pinB'], $c['bartenderA'], $c['pinA']);
    cbTick();
    $openAtSeal->update(['status' => 'ready']); // marked ready after the seal
    $later = cbSale($c['product'], $c['waiterOne'], 1);

    $open = CountOpenOrder::where('count_session_id', $session->id)->get();
    expect($open->pluck('order_id')->all())->toBe([$openAtSeal->id])
        ->and($open->first()->waiter_name)->toBe('Staff One')
        ->and((float) $open->first()->quantity)->toBe(2.0)
        ->and($open->first()->placed_at)->not->toBeNull();

    expect((new CountBreakdownViewService)->payload($session->fresh(), false)['open_orders'])->toHaveCount(1);
});

it('badges a repeat shortage at the threshold and not below it, using the settings', function () {
    \Illuminate\Support\Carbon::setTestNow(now()->startOfMinute());
    $c = cbSetup(50);
    $short = fn () => [$c['product']->id => (float) \App\Models\InventoryItem::where('warehouse_id', $c['bar']->id)->value('quantity') - 1];

    $first = cbSeal($c['bar'], $c['bartenderA'], $c['pinA'], $c['bartenderB'], $c['pinB'], $short());
    cbTick();
    $second = cbSeal($c['bar'], $c['bartenderB'], $c['pinB'], $c['bartenderA'], $c['pinA'], $short());
    cbTick();

    expect(cbPayloadLine($second)['repeat'])->toBeNull(); // 2 of 2, threshold 3

    $third = cbSeal($c['bar'], $c['bartenderA'], $c['pinA'], $c['bartenderB'], $c['pinB'], $short());
    expect(cbPayloadLine($third)['repeat'])->toBe(['short' => 3, 'of' => 3]);

    // Staff never get the badge.
    expect(cbPayloadLine($third, false))->not->toHaveKey('repeat');

    SettingsService::set(CountBreakdownSettings::REPEAT_SHORTAGE_THRESHOLD, '4', 'string', $c['manager']->id);
    expect(cbPayloadLine($third)['repeat'])->toBeNull();

    SettingsService::set(CountBreakdownSettings::REPEAT_SHORTAGE_THRESHOLD, '2', 'string', $c['manager']->id);
    SettingsService::set(CountBreakdownSettings::REPEAT_SHORTAGE_WINDOW, '2', 'string', $c['manager']->id);
    expect(cbPayloadLine($second)['repeat'])->toBe(['short' => 2, 'of' => 2])
        ->and(cbPayloadLine($first)['repeat'])->toBeNull();
});

it('lets only participants add append-only notes, and closes notes once the manager rules', function () {
    $c = cbFullScenario(25);
    $line = $c['line']->fresh();
    $notes = new CountVarianceNoteService;

    $note = $notes->add($line, $c['bartenderB'], 'Two bottles broke during the rush');
    expect($note->author_name)->toBe('Bola Bartender');
    $notes->add($line, $c['bartenderA'], 'Agreed, saw the broken glass');
    expect($line->notes()->count())->toBe(2);

    $stranger = User::factory()->create();
    expect(fn () => $notes->add($line, $stranger, 'Not mine to say'))->toThrow(Exception::class, 'Only staff who took part');

    expect(fn () => $note->update(['body' => 'edited']))->toThrow(AppendOnlyViolation::class);

    // Through the page, as the outgoing bartender.
    cbGrantDetailPage();
    $result = Livewire::actingAs($c['bartenderB'])
        ->test(CountSessionDetail::class, ['session_id' => $c['session']->id])
        ->instance()->addVarianceNote($line->id, 'Third note');
    expect($result['note']['body'])->toBe('Third note')->and($result['can_note'])->toBeTrue();

    (new CountSessionService)->debitDiscrepancy(HandoverDiscrepancy::firstOrFail(), $c['manager']->id);

    expect($notes->isOpenForNotes($line->fresh()))->toBeFalse();
    expect(fn () => $notes->add($line->fresh(), $c['bartenderB'], 'Too late'))->toThrow(Exception::class, 'already ruled');
    expect(cbPayloadLine($c['session'], false, $c['bartenderB'])['can_note'])->toBeFalse();

    // The ruling and the booked debt show in the variance pop-up, read-only.
    $ruling = cbPayloadLine($c['session'])['ruling'];
    expect($ruling['label'])->toBe('Charged to staff')
        ->and($ruling['debt'])->toBe(['name' => 'Bola Bartender', 'amount' => 2000.0]);
});

it('flags a slow ticket from the settings threshold, exactly at the boundary', function () {
    \Illuminate\Support\Carbon::setTestNow(now()->startOfMinute());
    $c = cbSetup(30);
    cbSeal($c['bar'], $c['bartenderA'], $c['pinA'], $c['bartenderB'], $c['pinB']);
    cbTick();

    $onTime = cbSale($c['product'], $c['waiterOne'], 1);
    cbTick(30);
    $onTime->update(['status' => 'ready']);

    $slow = cbSale($c['product'], $c['waiterTwo'], 1);
    cbTick(31);
    $slow->update(['status' => 'ready']);
    cbTick();

    $session = cbSeal($c['bar'], $c['bartenderB'], $c['pinB'], $c['bartenderA'], $c['pinA']);
    $sold = fn () => collect(cbPayloadLine($session)['movements']['sold'])->keyBy('waiter');

    expect($sold()['Staff One']['ready_minutes'])->toBe(30)
        ->and($sold()['Staff One']['slow'])->toBeFalse()
        ->and($sold()['Staff Two']['ready_minutes'])->toBe(31)
        ->and($sold()['Staff Two']['slow'])->toBeTrue()
        ->and(cbPayloadLine($session)['slow_count'])->toBe(1);

    SettingsService::set(CountBreakdownSettings::SLOW_RELEASE_MINUTES, '45', 'string', $c['manager']->id);
    expect($sold()['Staff Two']['slow'])->toBeFalse();
});

it('folds only items with no movement and no variance', function () {
    \Illuminate\Support\Carbon::setTestNow(now()->startOfMinute());
    $c = cbSetup(20);
    $quietProduct = \App\Models\Product::create(['name' => 'Malt', 'price' => 500, 'category_id' => $c['product']->category_id, 'is_active' => true]);
    \App\Models\InventoryItem::create(['product_id' => $quietProduct->id, 'warehouse_id' => $c['bar']->id, 'quantity' => 6]);

    cbSeal($c['bar'], $c['bartenderA'], $c['pinA'], $c['bartenderB'], $c['pinB']);
    cbTick();
    cbSale($c['product'], $c['waiterOne'], 4);
    cbTick();
    $session = cbSeal($c['bar'], $c['bartenderB'], $c['pinB'], $c['bartenderA'], $c['pinA']);

    $lines = collect((new CountBreakdownViewService)->payload($session->fresh(), false)['sections'][0]['lines'])->keyBy('name');

    expect($lines['Star Lager']['variance'])->toBe(0.0)
        ->and($lines['Star Lager']['quiet'])->toBeFalse()
        ->and($lines['Malt']['quiet'])->toBeTrue();
});

it('shows counts sealed before this update as not recorded, without errors', function () {
    $c = cbFullScenario(25);
    cbGrantDetailPage();

    // Simulate a pre-update count: no breakdown rows at all.
    DB::table('count_breakdowns')->where('count_session_id', $c['session']->id)->delete();

    $payload = (new CountBreakdownViewService)->payload($c['session']->fresh(), true);
    expect($payload['recorded'])->toBeFalse();

    Livewire::actingAs($c['bartenderA'])->test(CountSessionDetail::class, ['session_id' => $c['session']->id])
        ->assertSee('Breakdown not recorded (before this update).')
        ->assertSee('Final Comparison');

    $this->actingAs($c['bartenderA'])->get(route('count-breakdown.pdf', $c['session']->id))->assertOk();
});

it('breaks down a kitchen count by ingredient, with usage per dish and waiter', function () {
    \Illuminate\Support\Carbon::setTestNow(now()->startOfMinute());
    $c = cbSetup(0);
    $kitchen = \App\Models\WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer', 'is_active' => 1]);
    \Illuminate\Support\Facades\Cache::flush();

    $rice = \App\Models\Ingredient::create(['name' => 'Rice', 'sku' => 'RICE-1', 'unit_name' => 'kg', 'quantity' => 0, 'cost_per_unit' => 800, 'category' => 'Grains']);
    \App\Models\IngredientInventoryItem::create(['ingredient_id' => $rice->id, 'warehouse_id' => $kitchen->id, 'quantity' => 10]);
    \App\Models\IngredientTransaction::create(['ingredient_id' => $rice->id, 'warehouse_id' => $kitchen->id, 'type' => 'purchase', 'quantity' => 10, 'cost_per_unit' => 800, 'reference' => 'seed', 'user_id' => $c['manager']->id]);
    $jollof = \App\Models\MenuItem::create(['name' => 'Jollof Rice', 'sku' => 'MENU-JOLLOF', 'type' => 'food', 'sale_price' => 3000]);
    \App\Models\Recipe::create(['menu_item_id' => $jollof->id, 'ingredient_id' => $rice->id, 'quantity_needed' => 0.5]);

    $pin = 9100;
    $chef = function (string $name) use (&$pin) {
        $u = User::factory()->create(['name' => $name]);
        $u->assignRole('chef');
        (new \App\Services\PinAuthService)->setPin($u, (string) (++$pin));

        return [$u, (string) $pin];
    };
    [$chefA, $pinA] = $chef('Chef Ade');
    [$chefB, $pinB] = $chef('Chef Bisi');

    cbSeal($kitchen, $chefA, $pinA, $chefB, $pinB, [], 'kitchen_handover');
    cbTick();

    $order = \App\Models\Order::create(['order_number' => 'K-'.uniqid(), 'status' => 'paid', 'destination' => 'kitchen', 'total_amount' => 6000, 'user_id' => $c['waiterOne']->id]);
    \App\Models\OrderItem::create(['order_id' => $order->id, 'item_type' => 'menu_item', 'menu_item_id' => $jollof->id, 'product_name' => 'Jollof Rice', 'quantity' => 2, 'unit_price' => 3000, 'subtotal' => 6000]);
    \App\Services\InventoryService::deductInventoryForOrderItems($order->fresh('items'), allowShortfall: true);
    cbTick();

    $session = cbSeal($kitchen, $chefB, $pinB, $chefA, $pinA, [$rice->id => 8.5], 'kitchen_handover');

    $payload = (new CountBreakdownViewService)->payload($session->fresh(), true);
    $line = collect($payload['sections'][0]['lines'])->firstWhere('name', 'Rice');

    expect($payload['sections'][0]['key'])->toBe('ingredient')
        ->and($payload['sections'][0]['label'])->toBe('Ingredients')
        ->and($line['brought_forward'])->toBe(10.0)
        ->and($line['sold'])->toBe(1.0)
        ->and($line['expected'])->toBe(9.0)
        ->and($line['counted'])->toBe(8.5)
        ->and($line['variance'])->toBe(-0.5)
        ->and($line['value'])->toBe(-400.0)
        ->and($line['movements']['sold'][0]['waiter'])->toBe('Staff One')
        ->and($line['movements']['sold'][0]['dishes'])->toBe(['Jollof Rice ×2']);

    $db = CountBreakdownLine::where('count_session_id', $session->id)->where('item_id', $rice->id)->first();
    expect((float) $db->movements()->where('figure', 'sold')->where('status', 'active')->sum('quantity'))->toBe((float) $db->sold_qty);
});

it('generates staff and admin PDFs, with cost columns only in the admin one', function () {
    $c = cbFullScenario(25);
    (new CountVarianceNoteService)->add($c['line'], $c['bartenderB'], 'Two bottles broke');

    $staffPdf = $this->actingAs($c['bartenderA'])->get(route('count-breakdown.pdf', $c['session']->id));
    $staffPdf->assertOk()->assertHeader('content-type', 'application/pdf');
    expect($staffPdf->headers->get('content-disposition'))->not->toContain('admin');

    $adminPdf = $this->actingAs($c['manager'])->get(route('count-breakdown.pdf', $c['session']->id));
    $adminPdf->assertOk()->assertHeader('content-type', 'application/pdf');
    expect($adminPdf->headers->get('content-disposition'))->toContain('admin');

    $service = new CountBreakdownViewService;
    $staffHtml = view('pdf.count-breakdown', ['d' => $service->payload($c['session'], false)])->render();
    $adminHtml = view('pdf.count-breakdown', ['d' => $service->payload($c['session'], true)])->render();

    expect($staffHtml)->not->toContain('(cost)')->toContain('Two bottles broke')->toContain('₦2,000.00');
    expect($adminHtml)->toContain('Variance ₦ (cost)')->toContain('₦1,200.00')->toContain('Two bottles broke');
});

it('exports the breakdown and an item trace as CSV for auditors only', function () {
    $c = cbFullScenario(25);

    $csv = $this->actingAs($c['manager'])->get(route('count-breakdown.csv', $c['session']->id));
    $csv->assertOk();
    expect($csv->streamedContent())->toContain('Star Lager')->toContain('-2000');

    $trace = $this->actingAs($c['manager'])->get(route('count-item-trace.csv', ['section' => 'product', 'item' => $c['product']->id]));
    $trace->assertOk();
    expect(substr_count(trim($trace->streamedContent()), "\n"))->toBe(2); // header + 2 counts

    $this->actingAs($c['bartenderA'])->get(route('count-breakdown.csv', $c['session']->id))->assertForbidden();
    $this->actingAs($c['bartenderA'])->get(route('count-item-trace.csv', ['item' => $c['product']->id]))->assertForbidden();
});

it('renders the count history, item trace and CEO pages', function () {
    $c = cbFullScenario(25);

    Livewire::actingAs($c['manager'])->test(\App\Filament\Pages\CountSessions::class)
        ->assertCanSeeTableRecords([$c['session']])
        ->assertSee('9,000.00')
        ->filterTable('has_variance', true)
        ->assertCanSeeTableRecords([$c['session']])
        ->assertCanNotSeeTableRecords([$c['previous']]);

    Livewire::actingAs($c['manager'])->test(\App\Filament\Pages\CountItemTrace::class)
        ->set('itemId', $c['product']->id)
        ->assertSee('−2')
        ->assertDontSee('Pick a product or ingredient');

    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'ceo']);
    $ceo = User::factory()->create();
    $ceo->assignRole('ceo');

    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete)/i', $query->sql) && ! str_contains($query->sql, 'sessions')) {
            $writes[] = $query->sql;
        }
    });

    $this->actingAs($ceo)->get('/ceo/count-breakdowns')->assertOk()->assertSee('Count Breakdowns');
    $this->actingAs($ceo)->get('/ceo/count-breakdowns?session='.$c['session']->id)->assertOk()->assertSee('Count Breakdown')->assertSee('value_cost', false);
    $this->actingAs($ceo)->get(route('count-breakdown.pdf', $c['session']->id))->assertOk();

    expect($writes)->toBe([]);
});
