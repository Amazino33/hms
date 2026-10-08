<?php

namespace App\Filament\Ceo\Pages;

use App\Models\CountBreakdownLine;
use App\Models\CountSession;
use App\Models\User;
use App\Services\CountBreakdownViewService;
use BackedEnum;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * Read-only count history for the owner: every sealed count, its full
 * breakdown (with cost-price variance), and one item's trace across
 * counts. Reads the frozen snapshot only; nothing here writes.
 */
class CountBreakdowns extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Count Breakdowns';

    protected static ?string $title = 'Count Breakdowns';

    protected static ?string $slug = 'count-breakdowns';

    protected string $view = 'filament-ceo.pages.count-breakdowns';

    #[Url]
    public ?int $session = null;

    #[Url]
    public ?string $traceSection = null;

    #[Url]
    public ?int $traceItem = null;

    public ?string $from = null;

    public ?string $until = null;

    public ?int $staffId = null;

    public ?string $section = null;

    public bool $hasVariance = false;

    public function sessions()
    {
        return CountSession::query()
            ->with(['warehouse', 'outgoingUser', 'incomingUser'])
            ->where('status', 'reviewed')
            ->withSum('breakdownLines as breakdown_sales', 'sales_amount')
            ->withSum('breakdownLines as breakdown_variance_value', 'variance_value_selling')
            ->withSum('breakdownLines as breakdown_variance_cost', 'variance_value_cost')
            ->withExists('breakdown')
            ->when($this->from, fn ($q) => $q->where('reviewed_at', '>=', $this->from.' 00:00:00'))
            ->when($this->until, fn ($q) => $q->where('reviewed_at', '<=', $this->until.' 23:59:59'))
            ->when($this->staffId, fn ($q) => $q->where(fn ($w) => $w->where('outgoing_user_id', $this->staffId)->orWhere('incoming_user_id', $this->staffId)->orWhere('opened_by', $this->staffId)))
            ->when($this->section, fn ($q) => $q->whereHas('items', fn ($i) => $i->where('item_type', $this->section)))
            ->when($this->hasVariance, fn ($q) => $q->whereHas('items', fn ($i) => $i->where(fn ($v) => $v->where('variance', '>', 0.0001)->orWhere('variance', '<', -0.0001))))
            ->orderByDesc('reviewed_at')
            ->limit(100)
            ->get();
    }

    public function staffOptions(): array
    {
        return User::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function selectedSession(): ?CountSession
    {
        return $this->session ? CountSession::query()->where('status', 'reviewed')->find($this->session) : null;
    }

    public function payload(): ?array
    {
        $session = $this->selectedSession();

        return $session ? (new CountBreakdownViewService)->payload($session, true) : null;
    }

    public function traceOptions(): array
    {
        return CountBreakdownLine::query()
            ->where('section', $this->traceSection ?? 'product')
            ->orderBy('item_name')
            ->get(['item_id', 'item_name'])
            ->unique('item_id')
            ->pluck('item_name', 'item_id')
            ->all();
    }

    public function traceRows()
    {
        return $this->traceItem
            ? (new CountBreakdownViewService)->trace($this->traceSection ?? 'product', $this->traceItem)
            : collect();
    }

    public function updatedTraceSection(): void
    {
        $this->traceItem = null;
    }
}
