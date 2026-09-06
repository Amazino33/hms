<?php

namespace App\Filament\Pages;

use App\Models\Category;
use App\Models\CountSession;
use App\Models\CountSessionItem;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\StockTraceService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * "How many Amstel Malt did this waiter sell on this date, and does that
 * agree with what left the bar?" — the page that makes a count-session
 * discrepancy traceable.
 *
 * The Waiter Ledger already shows every order line, and the CEO Sales
 * Report already rolls a date range up per product. Neither answers the
 * question this page exists for, because the ledger has no totals per
 * day/item/waiter and the CEO report collapses the whole range into one
 * row per product with no day dimension.
 *
 * Note on the window: reaching for the date range when tracing a count is
 * the easy mistake. A handover runs 6pm to 7am and BusinessDay closes at
 * 9am WAT, so a hand-typed range both pulls in sales the previous count
 * already absorbed and misses ones after it. Picking the count session
 * instead fills in the exact boundaries — see
 * StockTraceService::windowForCountSession().
 */
class SalesTrace extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-magnifying-glass-circle';

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Sales Trace';

    protected static ?string $title = 'Sales Trace';

    protected string $view = 'filament.pages.sales-trace';

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    public string $dateFrom = '';

    public string $dateTo = '';

    public ?int $countSessionId = null;

    public ?int $waiterId = null;

    public ?int $categoryId = null;

    public ?string $itemType = null;

    public string $viewMode = 'long';

    /** Tally view flattens to one day at a time — that pivot has no room for a date axis. */
    public string $tallyDate = '';

    public function mount(): void
    {
        $this->dateFrom = CarbonImmutable::today()->subDays(6)->toDateString();
        $this->dateTo = CarbonImmutable::today()->toDateString();
        $this->tallyDate = CarbonImmutable::today()->toDateString();
    }

    public function setViewMode(string $mode): void
    {
        $this->viewMode = in_array($mode, ['long', 'tally'], true) ? $mode : 'long';
    }

    /**
     * Selecting a count session overrides the typed date range entirely —
     * showing both at once would leave it ambiguous which one produced the
     * numbers on screen.
     */
    public function updatedCountSessionId(): void
    {
        if (! $this->countSessionId) {
            return;
        }

        $session = CountSession::find($this->countSessionId);

        if (! $session) {
            return;
        }

        $window = $this->service()->windowForCountSession($session);

        $this->dateFrom = ($window['from'] ?? $session->opened_at)?->toDateString() ?? $this->dateFrom;
        $this->dateTo = $window['to']?->toDateString() ?? $this->dateTo;
    }

    public function clearCountSession(): void
    {
        $this->countSessionId = null;
    }

    private function service(): StockTraceService
    {
        return new StockTraceService;
    }

    /**
     * Per-request memos. #[Computed] only caches when these are read as
     * properties ($this->rows), and the view reaches for them as methods in
     * several places — without this the whole pivot query re-runs for the
     * table, the tally, the totals and the date selector on every render.
     * Private, so Livewire never tries to serialise them between requests;
     * a fresh instance per request is exactly the invalidation we want when
     * a filter changes.
     */
    private ?Collection $rowsMemo = null;

    private ?array $windowMemo = null;

    /**
     * @return array{from: CarbonImmutable, to: CarbonImmutable, label: string, exact: bool}
     */
    #[Computed]
    public function window(): array
    {
        return $this->windowMemo ??= $this->buildWindow();
    }

    /**
     * @return array{from: CarbonImmutable, to: CarbonImmutable, label: string, exact: bool}
     */
    private function buildWindow(): array
    {
        if ($this->countSessionId && ($session = CountSession::with('warehouse')->find($this->countSessionId))) {
            $window = $this->service()->windowForCountSession($session);

            return [
                'from' => CarbonImmutable::parse($window['from'] ?? $session->opened_at),
                'to' => CarbonImmutable::parse($window['to']),
                'label' => $window['from']
                    ? 'Exact count window: '.CarbonImmutable::parse($window['from'])->format('M j, g:i A').' to '.CarbonImmutable::parse($window['to'])->format('M j, g:i A')
                    : 'No earlier reviewed count at this warehouse — falling back to this session\'s open time.',
                'exact' => (bool) $window['from'],
            ];
        }

        return [
            'from' => CarbonImmutable::parse($this->dateFrom)->startOfDay(),
            'to' => CarbonImmutable::parse($this->dateTo)->endOfDay(),
            'label' => "Calendar range {$this->dateFrom} to {$this->dateTo} — may not line up with a count window.",
            'exact' => false,
        ];
    }

    #[Computed]
    public function rows(): Collection
    {
        if ($this->rowsMemo !== null) {
            return $this->rowsMemo;
        }

        $window = $this->window();

        return $this->rowsMemo = $this->service()->salesPivot($window['from'], $window['to'], array_filter([
            'waiter_id' => $this->waiterId,
            'category_id' => $this->categoryId,
            'item_type' => $this->itemType,
        ]));
    }

    #[Computed]
    public function totals(): array
    {
        $rows = $this->rows();

        return [
            'billed' => (float) $rows->sum('billed_quantity'),
            'revenue' => (float) $rows->sum('revenue'),
            'mismatches' => $rows->filter(fn ($r) => $r['mismatch'] !== null && abs($r['mismatch']) > 0.0001)->count(),
            'recipe_problems' => $rows->filter(fn ($r) => in_array($r['recipe_status'], ['missing', 'partial'], true))->count(),
        ];
    }

    /**
     * Products down the side, one column per waiter, for a single day —
     * the digital form of the handover sheet people already know.
     *
     * @return array{waiters: Collection, items: Collection}
     */
    #[Computed]
    public function tally(): array
    {
        $rows = $this->rows()->where('date', $this->tallyDate);

        return [
            'waiters' => $rows->groupBy('waiter_id')
                ->map(fn (Collection $group) => [
                    'id' => $group->first()['waiter_id'],
                    'name' => $group->first()['waiter_name'],
                    'total' => (float) $group->sum('revenue'),
                ])
                ->sortByDesc('total')
                ->values(),
            'items' => $rows->groupBy('item_key')
                ->map(fn (Collection $group) => [
                    'name' => $group->first()['item_name'],
                    'type' => $group->first()['item_type'],
                    'by_waiter' => $group->mapWithKeys(fn ($r) => [$r['waiter_id'] => $r['billed_quantity']])->all(),
                    'total' => (float) $group->sum('billed_quantity'),
                ])
                ->sortByDesc('total')
                ->values(),
        ];
    }

    /**
     * Days present in the current result set, so the tally selector only
     * ever offers a day that actually has sales on it.
     */
    #[Computed]
    public function availableDates(): Collection
    {
        return $this->rows()->pluck('date')->unique()->sort()->values();
    }

    /**
     * The full movement ladder for whichever count session is selected —
     * the part that can actually close a variance, since sales alone never
     * explain a transfer, a void or a damage write-off.
     */
    #[Computed]
    public function ladders(): Collection
    {
        if (! $this->countSessionId) {
            return collect();
        }

        $session = CountSession::with('warehouse')->find($this->countSessionId);

        if (! $session) {
            return collect();
        }

        $service = $this->service();

        return CountSessionItem::query()
            ->where('count_session_id', $session->id)
            ->with(['product', 'ingredient'])
            ->get()
            // Only items that actually landed off are worth a ladder; a
            // clean line needs no forensic trail and would bury the ones
            // that do under hundreds of zero rows.
            ->filter(fn (CountSessionItem $item) => $item->variance !== null && abs((float) $item->variance) > 0.0001)
            ->map(function (CountSessionItem $item) use ($session, $service) {
                $ladder = $service->ladder($session, $item);
                $ladder['item_name'] = $item->item_type === 'product'
                    ? ($item->product?->name ?? 'Unknown product')
                    : ($item->ingredient?->name ?? 'Unknown ingredient');
                $ladder['item_type'] = $item->item_type;

                return $ladder;
            })
            ->sortBy('item_name')
            ->values();
    }

    #[Computed]
    public function countSessions(): Collection
    {
        return CountSession::query()
            ->with('warehouse')
            ->whereIn('status', ['pending_review', 'reviewed'])
            ->orderByDesc('opened_at')
            ->limit(60)
            ->get();
    }

    #[Computed]
    public function waiters(): Collection
    {
        return User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['waiter', 'cashier', 'receptionist']))
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function categories(): Collection
    {
        return Category::orderBy('name')->get();
    }

    public function exportCsv(): StreamedResponse
    {
        $rows = $this->rows();
        $filename = 'sales-trace-'.CarbonImmutable::now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Date', 'Item', 'Type', 'Category', 'Waiter',
                'Billed Qty', 'Deducted Qty', 'Mismatch', 'Recipe Status', 'Revenue', 'Orders',
            ]);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['date'],
                    $row['item_name'],
                    $row['item_type'] === 'product' ? 'Product' : 'Menu item',
                    $row['category_name'],
                    $row['waiter_name'],
                    $row['billed_quantity'],
                    $row['deducted_quantity'] ?? 'n/a',
                    $row['mismatch'] ?? 'n/a',
                    $row['recipe_status'] ?? 'n/a',
                    $row['revenue'],
                    $row['order_count'],
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
