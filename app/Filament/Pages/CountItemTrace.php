<?php

namespace App\Filament\Pages;

use App\Models\CountBreakdownLine;
use App\Services\CountBreakdownViewService;
use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

/**
 * One product's or ingredient's line from every recorded count, oldest
 * first, so a shortage that keeps coming back across shifts is visible.
 * Gated on the Count Sessions permission: same audience, nothing extra to
 * grant after deploy.
 */
class CountItemTrace extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Count Item Trace';

    protected static ?string $title = 'Count Item Trace';

    protected static ?string $slug = 'count-item-trace';

    protected string $view = 'filament.pages.count-item-trace';

    public string $section = 'product';

    public ?int $itemId = null;

    public static function canAccess(): bool
    {
        return CountBreakdownViewService::canAudit();
    }

    public function mount(): void
    {
        $this->section = request()->query('section') === 'ingredient' ? 'ingredient' : 'product';
        $this->itemId = request()->integer('item') ?: null;
    }

    public function updatedSection(): void
    {
        $this->itemId = null;
    }

    /**
     * Only items that have been on at least one recorded count.
     *
     * @return array<int, string>
     */
    public function itemOptions(): array
    {
        return CountBreakdownLine::query()
            ->where('section', $this->section)
            ->orderBy('item_name')
            ->get(['item_id', 'item_name'])
            ->unique('item_id')
            ->pluck('item_name', 'item_id')
            ->all();
    }

    public function rows()
    {
        return $this->itemId ? (new CountBreakdownViewService)->trace($this->section, $this->itemId) : collect();
    }
}
