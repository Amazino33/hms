<?php

use App\Filament\Widgets\BartenderDebtWidget;
use App\Models\CountSession;
use App\Models\StaffDebt;
use App\Models\User;
use App\Models\WareHouse;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

it('shows a bartender their own outstanding debt total', function () {
    $bartender = User::factory()->create();
    $bartender->assignRole(Role::firstOrCreate(['name' => 'bartender']));

    $otherBartender = User::factory()->create();
    $otherBartender->assignRole(Role::firstOrCreate(['name' => 'bartender']));

    StaffDebt::create([
        'user_id' => $bartender->id,
        'amount' => 2000,
        'reason' => 'count_session_shortfall',
        'status' => 'open',
        'created_by' => $bartender->id,
    ]);

    // Someone else's debt must never show up in my total.
    StaffDebt::create([
        'user_id' => $otherBartender->id,
        'amount' => 9999,
        'reason' => 'count_session_shortfall',
        'status' => 'open',
        'created_by' => $otherBartender->id,
    ]);

    Livewire::actingAs($bartender)
        ->test(BartenderDebtWidget::class)
        ->call('load')
        ->assertSuccessful()
        ->assertSee('2,000.00')
        ->assertDontSee('9,999.00');
});

it('excludes settled debts from the outstanding total', function () {
    $bartender = User::factory()->create();
    $bartender->assignRole(Role::firstOrCreate(['name' => 'bartender']));

    StaffDebt::create([
        'user_id' => $bartender->id,
        'amount' => 3000,
        'reason' => 'count_session_shortfall',
        'status' => 'settled',
        'created_by' => $bartender->id,
    ]);

    Livewire::actingAs($bartender)
        ->test(BartenderDebtWidget::class)
        ->call('load')
        ->assertSuccessful()
        ->assertSee('No open debts');
});

it('is only visible to bartenders, chefs, and super admins', function () {
    $waiter = User::factory()->create();
    $waiter->assignRole(Role::firstOrCreate(['name' => 'waiter']));
    $this->actingAs($waiter);
    expect(BartenderDebtWidget::canView())->toBeFalse();

    $bartender = User::factory()->create();
    $bartender->assignRole(Role::firstOrCreate(['name' => 'bartender']));
    $this->actingAs($bartender);
    expect(BartenderDebtWidget::canView())->toBeTrue();
});

it('counts only reviewed handovers where the user was the outgoing custodian', function () {
    $bartender = User::factory()->create();
    $bartender->assignRole(Role::firstOrCreate(['name' => 'bartender']));
    $incoming = User::factory()->create();
    $bar = WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'is_active' => 1]);

    CountSession::create([
        'type' => 'bar_handover',
        'warehouse_id' => $bar->id,
        'status' => 'reviewed',
        'opened_by' => $bartender->id,
        'opened_at' => now(),
        'outgoing_user_id' => $bartender->id,
        'incoming_user_id' => $incoming->id,
        'reviewed_at' => now(),
    ]);

    Livewire::actingAs($bartender)
        ->test(BartenderDebtWidget::class)
        ->call('load')
        ->assertSuccessful()
        ->assertSee('1', escape: false);
});
