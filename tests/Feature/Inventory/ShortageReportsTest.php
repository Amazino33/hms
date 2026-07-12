<?php

use App\Filament\Pages\HandoverDiscrepancies;
use App\Filament\Pages\ShortageReports;
use App\Models\HandoverDiscrepancy;
use App\Models\User;
use Livewire\Livewire;

it('flags a pending_investigation line older than 2 days but not a fresher one', function () {
    sealedHandoverScenario(24, 20);
    sealedHandoverScenario(24, 22);

    $manager = User::factory()->create();
    [$old, $fresh] = HandoverDiscrepancy::all()->all();

    (new \App\Services\CountSessionService())->pendDiscrepancyInvestigation($old, 'Old one', $manager->id);
    (new \App\Services\CountSessionService())->pendDiscrepancyInvestigation($fresh, 'Fresh one', $manager->id);

    $old->update(['updated_at' => now()->subDays(3)]);

    expect($old->fresh()->isAgingInvestigation())->toBeTrue();
    expect($fresh->fresh()->isAgingInvestigation())->toBeFalse();

    expect(HandoverDiscrepancies::getNavigationBadge())->toBe('1');
});

it('computes the per-product shortage trend and monthly bartender summary from discrepancy data', function () {
    ['outgoing' => $bartender] = sealedHandoverScenario(24, 20); // 4 short, ₦2000

    $manager = User::factory()->create();
    $manager->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']));

    $component = Livewire::actingAs($manager)->test(ShortageReports::class);

    $trend = $component->instance()->shortageTrend();
    expect($trend[0]['total_value'])->toBe(2000.0);

    $summary = collect($component->instance()->monthlySummary());
    $row = $summary->firstWhere('name', $bartender->name);
    expect($row)->not->toBeNull();
    expect($row['total_shortage'])->toBe(2000.0);
});
