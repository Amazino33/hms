<?php

namespace App\Filament\Pages;

use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\WareHouse;
use App\Services\InventoryService;
use App\Services\PermissionService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * A physical count (from a spreadsheet, WhatsApp list, whatever) needs to
 * become the system's truth for a warehouse in one pass — this is the
 * counting feature's stocktake-correction half, usable on its own while
 * the guided count-session flow (MyCount/CountSessionDetail) is disabled.
 * Deliberately a two-step preview-then-apply flow: nothing is written
 * until the person operating it has seen exactly what will change,
 * including which products are about to be zeroed out for not appearing
 * in the pasted list.
 */
class BulkStockSet extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';
    protected static string|UnitEnum|null $navigationGroup = 'Inventory';
    protected static ?string $navigationLabel = 'Bulk Stock Set';
    protected static ?string $title = 'Bulk Stock Set';
    protected string $view = 'filament.pages.bulk-stock-set';

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    public ?int $warehouseId = null;

    public string $pasteData = '';

    public bool $previewed = false;

    /** @var array<int, array{product_id: int, name: string, old_qty: float, new_qty: float}> */
    public array $matched = [];

    /** @var array<int, string> */
    public array $unmatched = [];

    /** @var array<int, array{product_id: int, name: string, old_qty: float}> */
    public array $zeroedOut = [];

    public function mount(): void
    {
        $this->warehouseId = InventoryService::getBarWarehouseId();
    }

    public function warehouses()
    {
        return WareHouse::pluck('name', 'id');
    }

    protected function normalize(string $name): string
    {
        return Str::of($name)->lower()->squish()->toString();
    }

    public function preview(): void
    {
        $this->matched = [];
        $this->unmatched = [];
        $this->zeroedOut = [];
        $this->previewed = false;

        if (!$this->warehouseId) {
            Notification::make()->title('Choose a warehouse first')->warning()->send();
            return;
        }

        $products = Product::query()->get(['id', 'name']);
        $byNormalizedName = $products->keyBy(fn ($p) => $this->normalize($p->name));

        $matchedProductIds = [];

        foreach (preg_split('/\r\n|\r|\n/', trim($this->pasteData)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Accept comma or tab separated "Name, Quantity"
            $parts = preg_split('/,|\t/', $line, 2);
            if (count($parts) < 2) {
                $this->unmatched[] = $line . '  (no quantity found on this line)';
                continue;
            }

            [$name, $qty] = $parts;
            $name = trim($name);
            $qty = trim($qty);

            // Header row guard, e.g. "Description, current quantity"
            if (!is_numeric($qty)) {
                continue;
            }

            $product = $byNormalizedName->get($this->normalize($name));

            if (!$product) {
                $this->unmatched[] = "{$name}, {$qty}";
                continue;
            }

            $matchedProductIds[] = $product->id;

            $currentQty = (float) InventoryItem::where('product_id', $product->id)
                ->where('warehouse_id', $this->warehouseId)
                ->value('quantity');

            $this->matched[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'old_qty' => $currentQty,
                'new_qty' => (float) $qty,
            ];
        }

        $existing = InventoryItem::where('warehouse_id', $this->warehouseId)
            ->whereNotIn('product_id', $matchedProductIds)
            ->where('quantity', '!=', 0)
            ->with('product:id,name')
            ->get();

        foreach ($existing as $item) {
            $this->zeroedOut[] = [
                'product_id' => $item->product_id,
                'name' => $item->product?->name ?? "Product #{$item->product_id}",
                'old_qty' => (float) $item->quantity,
            ];
        }

        $this->previewed = true;
    }

    public function apply(): void
    {
        if (!$this->previewed) {
            return;
        }

        DB::transaction(function () {
            foreach ($this->matched as $row) {
                $this->setQuantity($row['product_id'], $row['new_qty'], $row['old_qty']);
            }

            foreach ($this->zeroedOut as $row) {
                $this->setQuantity($row['product_id'], 0.0, $row['old_qty']);
            }
        });

        Notification::make()
            ->title('Bar stock updated')
            ->body(count($this->matched) . ' set, ' . count($this->zeroedOut) . ' zeroed out')
            ->success()
            ->send();

        $this->pasteData = '';
        $this->matched = [];
        $this->unmatched = [];
        $this->zeroedOut = [];
        $this->previewed = false;
    }

    private function setQuantity(int $productId, float $newQty, float $oldQty): void
    {
        InventoryItem::updateOrCreate(
            ['product_id' => $productId, 'warehouse_id' => $this->warehouseId],
            []
        )->update(['quantity' => $newQty]);

        $variance = $newQty - $oldQty;

        if (abs($variance) < 0.0001) {
            return;
        }

        InventoryTransaction::create([
            'product_id' => $productId,
            'warehouse_id' => $this->warehouseId,
            'type' => 'adjustment',
            'quantity' => abs($variance),
            'reference' => 'bulk_stock_set:' . now()->toDateString(),
            'user_id' => auth()->id(),
        ]);
    }
}
