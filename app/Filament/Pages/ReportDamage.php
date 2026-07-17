<?php

namespace App\Filament\Pages;

use App\Models\Ingredient;
use App\Models\Product;
use App\Models\WareHouse;
use App\Services\DamageReportService;
use App\Services\PermissionService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use UnitEnum;

/**
 * Storekeeper's damage/write-off entry point — a new standalone page
 * (not folded into QuickInventoryUpdate/StorekeeperTransfers, so those
 * stay untouched). Deliberately shows no stock quantities anywhere:
 * blind-count protection applies here exactly as it does to the count
 * screens, since the same person could otherwise use "how much is left"
 * to game a report. Only the item's identity and the reported quantity.
 */
class ReportDamage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';
    protected static string|UnitEnum|null $navigationGroup = 'Inventory';
    protected static ?string $navigationLabel = 'Report Damage';
    protected static ?int $navigationSort = 19;
    protected string $view = 'filament.pages.report-damage';

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    public string $itemType = 'product';

    public ?int $productId = null;

    public ?int $ingredientId = null;

    public string $enteredUnit = 'base_unit';

    public ?float $enteredQty = null;

    public string $note = '';

    public function getViewData(): array
    {
        return [
            'products' => Product::orderBy('name')->get(['id', 'name', 'base_unit', 'purchase_unit_name', 'units_per_purchase_unit']),
            'ingredients' => Ingredient::orderBy('name')->get(['id', 'name', 'unit_name', 'purchase_unit_name', 'units_per_purchase_unit']),
        ];
    }

    public function submit(): void
    {
        try {
            $baseQty = $this->resolveBaseQuantity();
            $warehouse = WareHouse::where('type', 'storage')->first() ?? WareHouse::first();

            if (! $warehouse) {
                throw new \Exception('No store warehouse is configured.');
            }

            app(DamageReportService::class)->report(
                [
                    'product_id' => $this->itemType === 'product' ? $this->productId : null,
                    'ingredient_id' => $this->itemType === 'ingredient' ? $this->ingredientId : null,
                    'quantity' => $baseQty,
                    'note' => $this->note,
                ],
                $warehouse->id,
                auth()->id(),
            );

            $this->reset(['productId', 'ingredientId', 'enteredQty', 'note']);
            $this->enteredUnit = 'base_unit';

            Notification::make()->success()->title('Damage reported — awaiting manager approval')->send();
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->danger()->title('Could not report damage')->body($e->getMessage())->persistent()->send();
        }
    }

    private function resolveBaseQuantity(): float
    {
        if (! $this->productId && ! $this->ingredientId) {
            throw new \Exception('Choose an item first.');
        }

        if ((float) ($this->enteredQty ?? 0) <= 0) {
            throw new \Exception('Enter a quantity greater than zero.');
        }

        if (trim($this->note) === '') {
            throw new \Exception('A note is required.');
        }

        $unitsPerPurchaseUnit = $this->itemType === 'product'
            ? Product::find($this->productId)?->units_per_purchase_unit
            : Ingredient::find($this->ingredientId)?->units_per_purchase_unit;

        if ($this->enteredUnit === 'purchase_unit' && $unitsPerPurchaseUnit) {
            return (float) $this->enteredQty * $unitsPerPurchaseUnit;
        }

        return (float) $this->enteredQty;
    }
}
