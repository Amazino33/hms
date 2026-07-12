<?php

namespace App\Filament\Pages;

use App\Models\HandoverDiscrepancy;
use App\Models\StaffDebt;
use App\Models\StaffDebtRepayment;
use App\Models\User;
use App\Services\PermissionService;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use UnitEnum;

class ShortageReports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';
    protected static string|UnitEnum|null $navigationGroup = 'Inventory';
    protected static ?string $navigationLabel = 'Shortage Reports';
    protected static ?int $navigationSort = 18;
    protected string $view = 'filament.pages.shortage-reports';

    public ?string $from = null;
    public ?string $until = null;
    public ?string $month = null;

    public function mount(): void
    {
        $this->from = now()->subDays(30)->toDateString();
        $this->until = now()->toDateString();
        $this->month = now()->format('Y-m');
    }

    /**
     * Which products/ingredients show up as shortages most often and at
     * what cumulative ₦, over the selected date range — surfaces leak
     * patterns and seeder pack-size errors. Built from HandoverDiscrepancy's
     * frozen naira_value, never a live price.
     */
    public function shortageTrend(): array
    {
        $discrepancies = HandoverDiscrepancy::query()
            ->whereBetween('created_at', [$this->from . ' 00:00:00', $this->until . ' 23:59:59'])
            ->with(['item.product', 'item.ingredient'])
            ->get();

        return $discrepancies
            ->groupBy(fn (HandoverDiscrepancy $d) => $d->item->itemName())
            ->map(fn ($group, $name) => [
                'name' => $name,
                'occurrences' => $group->count(),
                'total_quantity' => $group->sum('shortfall_quantity'),
                'total_value' => $group->sum('naira_value'),
            ])
            ->sortByDesc('total_value')
            ->values()
            ->all();
    }

    /**
     * Per bartender/chef per selected month: shortage ₦ incurred, debited,
     * written off, repaid within the month, and their current outstanding
     * balance (a point-in-time figure, not month-scoped).
     */
    public function monthlySummary(): array
    {
        $start = Carbon::parse($this->month . '-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        // whereHas (not Spatie's role() scope) so this doesn't throw if
        // either role hasn't been created yet in this environment.
        $custodians = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['bartender', 'chef']))->get();

        return $custodians->map(function (User $user) use ($start, $end) {
            $discrepancies = HandoverDiscrepancy::query()
                ->whereHas('item.session', fn ($q) => $q->where('outgoing_user_id', $user->id))
                ->whereBetween('created_at', [$start, $end])
                ->get();

            $repaidThisMonth = StaffDebtRepayment::whereHas('staffDebt', fn ($q) => $q->where('user_id', $user->id))
                ->whereBetween('created_at', [$start, $end])
                ->sum('amount');

            $outstandingNow = StaffDebt::where('user_id', $user->id)
                ->whereIn('status', ['open', 'partially_settled'])
                ->get()
                ->sum(fn (StaffDebt $d) => $d->remainingBalance());

            return [
                'name' => $user->name,
                'total_shortage' => $discrepancies->sum('naira_value'),
                'total_debited' => $discrepancies->where('status', 'debited')->sum('naira_value'),
                'total_written_off' => $discrepancies->where('status', 'written_off')->sum('naira_value'),
                'total_repaid' => (float) $repaidThisMonth,
                'outstanding_now' => $outstandingNow,
            ];
        })
        ->filter(fn (array $row) => $row['total_shortage'] > 0 || $row['outstanding_now'] > 0)
        ->sortByDesc('total_shortage')
        ->values()
        ->all();
    }

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }
}
