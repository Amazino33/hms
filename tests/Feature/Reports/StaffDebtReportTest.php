<?php

use App\Filament\Pages\StaffDebtReport;
use App\Models\PagePermission;
use App\Models\Shift;
use App\Models\StaffDebt;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\StaffDebtReportService;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function debtFor(User $staff, array $attributes = []): StaffDebt
{
    return StaffDebt::create(array_merge([
        'user_id' => $staff->id,
        'amount' => 5000,
        'reason' => 'shift_shortfall',
        'status' => 'open',
        'created_by' => $attributes['created_by'] ?? $staff->id,
    ], $attributes));
}

function debtReportAdmin(): User
{
    $admin = User::factory()->create(['name' => 'Owner']);
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));

    return $admin;
}

/**
 * Pages are deny-by-default via PagePermission — a report that can export
 * every staff member's debts must not quietly become visible to every
 * authenticated panel user just because the file was added.
 */
it('denies access to a role with no page permission granted', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'waiter']));

    $this->actingAs($user);

    expect(PermissionService::canAccessPage(StaffDebtReport::class))->toBeFalse();
});

it('allows a role that has been granted the page', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'manager']));

    PagePermission::create([
        'page_class' => StaffDebtReport::class,
        'page_name' => 'Staff Debt Report',
        'role_name' => 'manager',
    ]);

    $this->actingAs($user);

    expect(PermissionService::canAccessPage(StaffDebtReport::class))->toBeTrue();
});

it('is discoverable by the page permissions manager so it can actually be granted', function () {
    expect(array_keys(PermissionService::getAvailablePages()))->toContain(StaffDebtReport::class);
});

it('renders the debts with the staff name, the date and who recorded it', function () {
    $admin = debtReportAdmin();
    $staff = User::factory()->create(['name' => 'Chidi']);
    $supervisor = User::factory()->create(['name' => 'Ngozi']);

    debtFor($staff, ['amount' => 7500, 'created_by' => $supervisor->id]);

    Livewire::actingAs($admin)
        ->test(StaffDebtReport::class)
        ->assertOk()
        ->assertSee('Chidi')
        ->assertSee('Ngozi')
        ->assertSee('7,500.00');
});

it('separates debts raised during a handover from ones a person recorded', function () {
    $admin = debtReportAdmin();
    $staff = User::factory()->create(['name' => 'Emeka']);

    $shift = Shift::create([
        'user_id' => $staff->id,
        'type' => 'bartender',
        'started_at' => now()->subHours(8),
        'status' => 'closed',
    ]);

    debtFor($staff, ['amount' => 3000, 'reason' => 'shift_shortfall', 'shift_id' => $shift->id]);
    debtFor($staff, ['amount' => 1200, 'reason' => 'count_session_shortfall']);
    debtFor($staff, ['amount' => 800, 'reason' => 'manual']);

    $rows = (new StaffDebtReportService)->rows();
    $summary = (new StaffDebtReportService)->summary($rows);

    expect($summary['handover_charged'])->toBe(4200.0)
        ->and($summary['recorded_charged'])->toBe(800.0)
        ->and($rows->where('reason', 'shift_shortfall')->first()['origin_label'])
        ->toBe('During handover (bartender shift)')
        ->and($rows->where('reason', 'manual')->first()['origin_label'])
        ->toBe('Recorded by a person');
});

it('filters to a single source', function () {
    $admin = debtReportAdmin();
    $staff = User::factory()->create();

    debtFor($staff, ['amount' => 3000, 'reason' => 'reception_shortfall']);
    debtFor($staff, ['amount' => 800, 'reason' => 'unpaid_order_conversion']);

    $service = new StaffDebtReportService;

    expect($service->rows(['origin' => 'handover'])->pluck('reason')->all())->toBe(['reception_shortfall'])
        ->and($service->rows(['origin' => 'recorded'])->pluck('reason')->all())->toBe(['unpaid_order_conversion']);
});

it('reports outstanding separately from the amount charged once part is repaid', function () {
    $staff = User::factory()->create();
    $manager = User::factory()->create();

    $debt = debtFor($staff, ['amount' => 10000]);
    $debt->repayments()->create([
        'amount' => 4000,
        'method' => 'cash',
        'recorded_by' => $manager->id,
    ]);
    $debt->refreshStatus();

    $rows = (new StaffDebtReportService)->rows();
    $row = $rows->first();

    expect($row['amount'])->toBe(10000.0)
        ->and($row['repaid'])->toBe(4000.0)
        ->and($row['outstanding'])->toBe(6000.0)
        ->and($row['status'])->toBe('partially_settled');
});

/**
 * "Pending and outstanding" is the whole point of the report — a settled
 * debt must never inflate the figure a manager is about to act on.
 */
it('excludes settled debts from the outstanding filter and total', function () {
    $staff = User::factory()->create();
    $manager = User::factory()->create();

    $settled = debtFor($staff, ['amount' => 2000]);
    $settled->repayments()->create(['amount' => 2000, 'method' => 'cash', 'recorded_by' => $manager->id]);
    $settled->refreshStatus();

    debtFor($staff, ['amount' => 5000]);

    $service = new StaffDebtReportService;
    $all = $service->rows();
    $pending = $service->rows(['status' => 'outstanding']);

    expect($all)->toHaveCount(2)
        ->and($pending)->toHaveCount(1)
        ->and($service->summary($all)['outstanding'])->toBe(5000.0)
        ->and($service->summary($all)['charged'])->toBe(7000.0)
        ->and($service->summary($all)['settled_count'])->toBe(1);
});

it('filters to several staff at once', function () {
    $chidi = User::factory()->create(['name' => 'Chidi']);
    $ngozi = User::factory()->create(['name' => 'Ngozi']);
    $emeka = User::factory()->create(['name' => 'Emeka']);

    debtFor($chidi, ['amount' => 1000]);
    debtFor($ngozi, ['amount' => 2000]);
    debtFor($emeka, ['amount' => 4000]);

    $rows = (new StaffDebtReportService)->rows(['user_ids' => [$chidi->id, $ngozi->id]]);

    expect($rows->pluck('staff_name')->sort()->values()->all())->toBe(['Chidi', 'Ngozi'])
        ->and((new StaffDebtReportService)->summary($rows)['charged'])->toBe(3000.0);
});

/**
 * The 9am WAT boundary, not the calendar: a shortfall raised at 2am
 * belongs to the night still being closed out. A calendar-day filter
 * would drop it out of the range the manager actually asked for.
 */
it('counts an after-midnight debt against the previous business day', function () {
    $staff = User::factory()->create();

    // 2am WAT on the 5th = 1am UTC, which BusinessDay places on the 4th.
    $debt = debtFor($staff, ['amount' => 1500]);
    $debt->forceFill(['created_at' => CarbonImmutable::parse('2026-09-05 01:00:00', 'UTC')])->save();

    $service = new StaffDebtReportService;

    expect($service->rows(['from' => '2026-09-04', 'to' => '2026-09-04'])->pluck('business_day')->all())
        ->toBe(['2026-09-04'])
        ->and($service->rows(['from' => '2026-09-05', 'to' => '2026-09-05']))
        ->toHaveCount(0)
        ->and(BusinessDay::labelFor($debt->fresh()->created_at))->toBe('2026-09-04');
});

it('rolls the same rows up per staff member, split by where the debt came from', function () {
    $chidi = User::factory()->create(['name' => 'Chidi']);
    $manager = User::factory()->create();

    debtFor($chidi, ['amount' => 6000, 'reason' => 'shift_shortfall']);
    $manual = debtFor($chidi, ['amount' => 4000, 'reason' => 'manual']);
    $manual->repayments()->create(['amount' => 1000, 'method' => 'cash', 'recorded_by' => $manager->id]);
    $manual->refreshStatus();

    $service = new StaffDebtReportService;
    $perStaff = $service->perStaff($service->rows());

    expect($perStaff)->toHaveCount(1);

    $row = $perStaff->first();

    expect($row['staff_name'])->toBe('Chidi')
        ->and($row['debts'])->toBe(2)
        ->and($row['charged'])->toBe(10000.0)
        ->and($row['repaid'])->toBe(1000.0)
        ->and($row['outstanding'])->toBe(9000.0)
        ->and($row['handover_outstanding'])->toBe(6000.0)
        ->and($row['recorded_outstanding'])->toBe(3000.0);
});

it('offers only staff who actually have a debt in the picker', function () {
    $withDebt = User::factory()->create(['name' => 'Chidi']);
    User::factory()->create(['name' => 'Never Owed Anything']);

    debtFor($withDebt);

    expect((new StaffDebtReportService)->staffWithDebts()->pluck('name')->all())->toBe(['Chidi']);
});

/** Captures what a streamed download actually writes, not just its name. */
function downloadedBody(\Symfony\Component\HttpFoundation\StreamedResponse $response): string
{
    ob_start();
    $response->sendContent();

    return ob_get_clean();
}

it('downloads a csv of exactly what the filters are showing', function () {
    $admin = debtReportAdmin();
    $chidi = User::factory()->create(['name' => 'Chidi']);
    $ngozi = User::factory()->create(['name' => 'Ngozi']);

    debtFor($chidi, ['amount' => 2500, 'reason' => 'shift_shortfall', 'created_by' => $admin->id]);
    debtFor($ngozi, ['amount' => 9999, 'reason' => 'manual', 'created_by' => $admin->id]);

    $component = Livewire::actingAs($admin)
        ->test(StaffDebtReport::class)
        ->set('userIds', [$chidi->id]);

    $component->call('exportCsv')
        ->assertFileDownloaded('staff-debts-'.$component->get('dateFrom').'-to-'.$component->get('dateTo').'.csv');

    $csv = downloadedBody($component->instance()->exportCsv());

    expect($csv)->toContain('Business Day')
        ->toContain('Outstanding')
        ->toContain('Recorded By')
        ->toContain('Chidi')
        ->toContain('During handover')
        ->toContain('2500.00')
        ->not->toContain('Ngozi');
});

it('downloads a per staff csv and a pdf', function () {
    $admin = debtReportAdmin();
    $staff = User::factory()->create(['name' => 'Chidi']);
    debtFor($staff, ['amount' => 2500, 'created_by' => $admin->id]);

    $component = Livewire::actingAs($admin)->test(StaffDebtReport::class);

    $csv = downloadedBody($component->instance()->exportStaffSummaryCsv());

    expect($csv)->toContain('Oldest Pending (days)')->toContain('Chidi');

    expect(downloadedBody($component->instance()->exportPdf()))->toStartWith('%PDF');
});

it('names the filters on the exported pdf so a partial export is never read as the whole ledger', function () {
    $admin = debtReportAdmin();
    $chidi = User::factory()->create(['name' => 'Chidi']);
    debtFor($chidi);

    $description = Livewire::actingAs($admin)
        ->test(StaffDebtReport::class)
        ->set('userIds', [$chidi->id])
        ->set('status', 'outstanding')
        ->set('origin', 'handover')
        ->instance()
        ->filtersDescription();

    expect($description)->toContain('Chidi')
        ->toContain('Pending / outstanding only')
        ->toContain('Raised during handover')
        ->toContain('9am WAT');
});

it('moves the date range onto business days for a preset', function () {
    $admin = debtReportAdmin();

    $component = Livewire::actingAs($admin)
        ->test(StaffDebtReport::class)
        ->call('setRange', 'today');

    $component->assertSet('dateFrom', BusinessDay::today())
        ->assertSet('dateTo', BusinessDay::today());
});
