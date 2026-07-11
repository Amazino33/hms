<?php

namespace App\Filament\Pages;

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Procurement;
use App\Models\Product;
use App\Models\WareHouse;
use App\Services\PermissionService;
use App\Services\ProcurementService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class NewProcurement extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shopping-cart';
    protected static ?string $navigationLabel = 'Record Procurement';
    protected static string|UnitEnum|null $navigationGroup = 'Inventory';
    protected static ?int $navigationSort = 14;
    protected string $view = 'filament.pages.new-procurement';

    // Defer the (potentially expensive) recent-procurements list, matching
    // StorekeeperTransfers/QuickInventoryUpdate's deferred-load convention.
    public bool $ready = false;

    public ?string $supplierName = null;
    public ?string $purchasedAt = null;

    /** @var array<int, array> */
    public array $productLines = [];

    /** @var array<int, array> */
    public array $ingredientLines = [];

    public function mount(): void
    {
        $this->purchasedAt = now()->toDateString();
    }

    public function load(): void
    {
        $this->ready = true;
    }

    public function getViewData(): array
    {
        $mainStore = WareHouse::where('type', 'storage')->first() ?? WareHouse::first();

        return [
            'products' => Product::orderBy('name')->get(['id', 'name', 'purchase_unit_name', 'units_per_purchase_unit', 'base_unit', 'category_id']),
            'ingredients' => Ingredient::orderBy('name')->get(['id', 'name', 'purchase_unit_name', 'units_per_purchase_unit', 'unit_name']),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'mainStore' => $mainStore,
            'recentProcurements' => $this->ready
                ? Procurement::with(['items.product', 'ingredientItems.ingredient', 'recordedBy'])
                    ->latest()
                    ->paginate(10, ['*'], 'page', request()->get('page', 1))
                : collect(),
        ];
    }

    public function addProductLine(array $line): void
    {
        $this->productLines[] = $line;
    }

    public function addIngredientLine(array $line): void
    {
        $this->ingredientLines[] = $line;
    }

    public function removeProductLine(int $index): void
    {
        unset($this->productLines[$index]);
        $this->productLines = array_values($this->productLines);
    }

    public function removeIngredientLine(int $index): void
    {
        unset($this->ingredientLines[$index]);
        $this->ingredientLines = array_values($this->ingredientLines);
    }

    public function save(): void
    {
        if (empty($this->productLines) && empty($this->ingredientLines)) {
            Notification::make()->danger()->title('Add at least one line before saving')->send();

            return;
        }

        $mainStore = WareHouse::where('type', 'storage')->first() ?? WareHouse::first();

        try {
            $procurement = app(ProcurementService::class)->commit(
                [
                    'location_id' => $mainStore->id,
                    'supplier_name' => $this->supplierName,
                    'purchased_at' => $this->purchasedAt,
                ],
                $this->productLines,
                $this->ingredientLines,
                Auth::id(),
            );
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Could not save procurement')->body($e->getMessage())->send();

            return;
        }

        $this->productLines = [];
        $this->ingredientLines = [];
        $this->supplierName = null;

        Notification::make()->success()->title("Procurement {$procurement->reference} saved")->send();
    }

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }
}
