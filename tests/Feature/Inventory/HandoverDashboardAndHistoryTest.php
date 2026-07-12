<?php

use App\Filament\Pages\MyHandoverHistory;
use App\Filament\Widgets\BartenderDebtWidget;
use App\Models\HandoverDiscrepancy;
use App\Models\PagePermission;
use App\Models\User;
use App\Services\CountSessionService;
use Livewire\Livewire;

it('shows pending shortages separately from confirmed debt on the bartender dashboard', function () {
    ['outgoing' => $outgoing] = sealedHandoverScenario(24, 20); // 4 short, 500 each = 2000 pending

    Livewire::actingAs($outgoing)
        ->test(BartenderDebtWidget::class)
        ->call('load')
        ->assertSuccessful()
        ->assertSee('2,000.00') // pending shortage
        ->assertSee('No open debts'); // nothing confirmed yet

    // Once a manager debits it, it moves from pending to confirmed.
    $discrepancy = HandoverDiscrepancy::first();
    $manager = User::factory()->create();
    (new CountSessionService())->debitDiscrepancy($discrepancy, $manager->id);

    Livewire::actingAs($outgoing)
        ->test(BartenderDebtWidget::class)
        ->call('load')
        ->assertSuccessful()
        ->assertSee('1 open debt(s)');
});

it('scopes My Handover History to the logged-in users own sessions only', function () {
    ['session' => $mySession, 'outgoing' => $mine] = sealedHandoverScenario(24, 20);
    ['session' => $otherSession, 'outgoing' => $someoneElses] = sealedHandoverScenario(24, 22);

    PagePermission::firstOrCreate(
        ['page_class' => MyHandoverHistory::class, 'role_name' => 'bartender'],
        ['page_class' => MyHandoverHistory::class, 'page_name' => 'My Handover History', 'role_name' => 'bartender']
    );

    Livewire::actingAs($mine)
        ->test(MyHandoverHistory::class)
        ->assertCanSeeTableRecords([$mySession->fresh()])
        ->assertCanNotSeeTableRecords([$otherSession->fresh()]);
});
