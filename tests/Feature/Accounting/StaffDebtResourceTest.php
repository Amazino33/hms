<?php

use App\Models\StaffDebt;
use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Livewire\Livewire;

it('lets a manager record a repayment against an open debt through the resource action', function () {
    $this->seed(ShieldSeeder::class);

    $manager = User::factory()->create();
    $manager->assignRole('manager');

    $waiter = User::factory()->create();
    $debt = StaffDebt::create([
        'user_id' => $waiter->id,
        'amount' => 3000,
        'reason' => 'shift_shortfall',
        'status' => 'open',
        'created_by' => $manager->id,
    ]);

    Livewire::actingAs($manager)
        ->test(\App\Filament\Resources\StaffDebts\Pages\ListStaffDebts::class)
        ->callTableAction('recordRepayment', $debt, data: [
            'amount' => 3000,
            'method' => 'cash',
        ]);

    $debt->refresh();
    expect($debt->status)->toBe('settled');
    expect($debt->repayments()->count())->toBe(1);
});

it('creates a manual debt with reason=manual and stamps the creator', function () {
    $this->seed(ShieldSeeder::class);

    $manager = User::factory()->create();
    $manager->assignRole('manager');
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');

    Livewire::actingAs($manager)
        ->test(\App\Filament\Resources\StaffDebts\Pages\CreateStaffDebt::class)
        ->fillForm([
            'user_id' => $waiter->id,
            'amount' => 1000,
            'notes' => 'Till was short at open',
        ])
        ->call('create');

    $debt = StaffDebt::firstOrFail();
    expect($debt->reason)->toBe('manual');
    expect($debt->status)->toBe('open');
    expect($debt->created_by)->toBe($manager->id);
});
