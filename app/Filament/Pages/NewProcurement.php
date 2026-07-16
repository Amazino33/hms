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

    /** Set once a procurement has actually been saved this page-visit —
     *  used only as extra context on the price-change activity log entry. */
    public ?string $lastProcurementReference = null;

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
            'products' => Product::orderBy('name')->get(['id', 'name', 'purchase_unit_name', 'units_per_purchase_unit', 'base_unit', 'category_id', 'price', 'last_cost_price']),
            'priceRoundingStep' => config('hms.price_rounding_step', 50),
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
            Notification::make()->danger()->title('Add at least one line before saving')->persistent()->send();

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
            Notification::make()->danger()->title('Could not save procurement')->body($e->getMessage())->persistent()->send();

            return;
        }

        $this->productLines = [];
        $this->ingredientLines = [];
        $this->supplierName = null;
        $this->lastProcurementReference = $procurement->reference;

        Notification::make()->success()->title("Procurement {$procurement->reference} saved")->send();
    }

    /**
     * One-tap (or manually-edited) selling-price update from the
     * procurement price panel. Storekeepers may only reach this through
     * this specific flow (the 'update-price-via-procurement' permission) —
     * general product price editing on the Products page stays gated by
     * the normal manager-only Update:Product permission, untouched here.
     */
    public function applyPriceSuggestion(int $productId, float $newPrice): void
    {
        if (!auth()->user()->can('update-price-via-procurement') && !auth()->user()->can('Update:Product')) {
            Notification::make()->danger()->title('You are not allowed to change prices')->persistent()->send();

            return;
        }

        $product = Product::find($productId);

        if (!$product) {
            return;
        }

        $oldPrice = (float) $product->price;
        $product->update(['price' => $newPrice]);

        activity('product_price')
            ->performedOn($product)
            ->causedBy(auth()->user())
            ->withProperties([
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
                'via' => 'procurement_entry',
                'procurement_reference' => $this->lastProcurementReference,
            ])
            ->log('Selling price updated via procurement entry');

        Notification::make()->success()->title('Price updated to ₦' . number_format($newPrice, 2))->send();
    }

    /**
     * Same formula as the price panel's live Alpine preview (new-procurement
     * .blade.php's `suggestedPrice` getter) — kept here too, as a pure PHP
     * method, purely so the math has one documented, testable source of
     * truth. The JS computes it instantly client-side for responsiveness
     * (no round-trip while typing); this mirrors it exactly. Preserves the
     * margin implied by (currentSellingPrice / lastCostPrice), applied to
     * the new unit cost, rounded up to the nearest $roundingStep — and only
     * returned when it's at least one full step above the current price.
     */
    public static function calculateSuggestedPrice(
        float $newUnitCost,
        ?float $currentSellingPrice,
        ?float $lastCostPrice,
        int $roundingStep,
    ): ?float {
        if (!$lastCostPrice || $lastCostPrice <= 0) {
            return null;
        }

        if ($currentSellingPrice === null || $newUnitCost <= 0) {
            return null;
        }

        $raw = $newUnitCost * ($currentSellingPrice / $lastCostPrice);
        $rounded = ceil($raw / $roundingStep) * $roundingStep;

        return ($rounded - $currentSellingPrice >= $roundingStep) ? $rounded : null;
    }

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }
}
