<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\IngredientInventoryItem;
use App\Models\IngredientTransaction;
use App\Models\IngredientTransferItem;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\TransferDiscrepancy;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockTransferService
{
    /**
     * Create a new stock transfer (storekeeper initiates). Each line may be
     * expressed either as a plain base-unit 'quantity' (legacy shape, still
     * supported) or as 'entered_qty' + 'entered_unit' (purchase_unit/
     * base_unit) for pack-aware entry — converted to base units and checked
     * against source stock before the transfer is created, since previously
     * nothing validated sufficiency until receipt.
     * $items = [ ['product_id' => 1, 'quantity' => 5], ... ]
     * $ingredientItems = [ ['ingredient_id' => 1, 'quantity' => 5], ... ]
     */
    public function createTransfer(int $fromWarehouseId, int $toWarehouseId, int $userId, array $items, array $ingredientItems = []): StockTransfer
    {
        return DB::transaction(function () use ($fromWarehouseId, $toWarehouseId, $userId, $items, $ingredientItems) {
            $transfer = StockTransfer::create([
                'transfer_number' => 'TR-' . time() . '-' . Str::upper(Str::random(4)),
                'from_warehouse_id' => $fromWarehouseId,
                'to_warehouse_id' => $toWarehouseId,
                'user_id' => $userId,
                'status' => 'pending',
            ]);

            foreach ($items as $it) {
                $product = Product::findOrFail($it['product_id']);
                [$baseQty, $enteredQty, $enteredUnit, $snapshot] = $this->resolveLineQuantities($it, $product->units_per_purchase_unit);

                $this->assertSufficientStock(InventoryItem::class, 'product_id', $product->id, $fromWarehouseId, $baseQty);

                StockTransferItem::create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id' => $product->id,
                    'quantity' => $baseQty,
                    'entered_qty' => $enteredQty,
                    'entered_unit' => $enteredUnit,
                    'units_per_purchase_unit_snapshot' => $snapshot,
                ]);
            }

            foreach ($ingredientItems as $it) {
                $ingredient = Ingredient::findOrFail($it['ingredient_id']);
                [$baseQty, $enteredQty, $enteredUnit, $snapshot] = $this->resolveLineQuantities($it, $ingredient->units_per_purchase_unit);

                $this->assertSufficientStock(IngredientInventoryItem::class, 'ingredient_id', $ingredient->id, $fromWarehouseId, $baseQty);

                IngredientTransferItem::create([
                    'stock_transfer_id' => $transfer->id,
                    'ingredient_id' => $ingredient->id,
                    'quantity' => $baseQty,
                    'entered_qty' => $enteredQty,
                    'entered_unit' => $enteredUnit,
                    'units_per_purchase_unit_snapshot' => $snapshot,
                ]);
            }

            return $transfer;
        });
    }

    private function resolveLineQuantities(array $it, ?int $unitsPerPurchaseUnit): array
    {
        $enteredUnit = $it['entered_unit'] ?? 'base_unit';
        $enteredQty = (float) ($it['entered_qty'] ?? $it['quantity']);
        $baseQty = PackConversionService::toBaseQty($enteredQty, $enteredUnit, $unitsPerPurchaseUnit);

        return [$baseQty, $enteredQty, $enteredUnit, $enteredUnit === 'purchase_unit' ? $unitsPerPurchaseUnit : null];
    }

    private function assertSufficientStock(string $inventoryModel, string $keyColumn, int $keyId, int $warehouseId, float $baseQty): void
    {
        $available = (float) ($inventoryModel::query()
            ->where($keyColumn, $keyId)
            ->where('warehouse_id', $warehouseId)
            ->value('quantity') ?? 0);

        if ($available < $baseQty) {
            throw new \Exception("Insufficient stock in source warehouse for {$keyColumn} {$keyId}");
        }
    }

    /**
     * Mark transfer as received: moves stock from source to destination for
     * both products and ingredients, logging a transaction row for each leg
     * of the move so the audit trail shows exactly where stock came from and
     * went to.
     */
    public function receiveTransfer(StockTransfer $transfer, int $receivedByUserId): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $receivedByUserId) {
            if ($transfer->status !== 'pending' && $transfer->status !== 'sent') {
                throw new \Exception('Transfer cannot be received.');
            }

            foreach ($transfer->items as $item) {
                $this->moveProductStock($transfer, $item, $receivedByUserId);
            }

            foreach ($transfer->ingredientItems as $item) {
                $this->moveIngredientStock($transfer, $item, $receivedByUserId);
            }

            $transfer->update(['status' => 'received']);

            return $transfer;
        });
    }

    private function moveProductStock(StockTransfer $transfer, StockTransferItem $item, int $userId): void
    {
        $from = InventoryItem::query()
            ->where('product_id', $item->product_id)
            ->where('warehouse_id', $transfer->from_warehouse_id)
            ->lockForUpdate()
            ->first();

        if (!$from || $from->quantity < $item->quantity) {
            throw new \Exception("Insufficient stock in source warehouse for product {$item->product_id}");
        }

        $from->decrement('quantity', $item->quantity);

        InventoryTransaction::create([
            'product_id' => $item->product_id,
            'warehouse_id' => $transfer->from_warehouse_id,
            'type' => 'transfer',
            'quantity' => $item->quantity,
            'reference' => "transfer:{$transfer->id}:out",
            'user_id' => $userId,
        ]);

        $to = InventoryItem::query()
            ->where('product_id', $item->product_id)
            ->where('warehouse_id', $transfer->to_warehouse_id)
            ->lockForUpdate()
            ->first();

        if ($to) {
            $to->increment('quantity', $item->quantity);
        } else {
            InventoryItem::create([
                'product_id' => $item->product_id,
                'warehouse_id' => $transfer->to_warehouse_id,
                'quantity' => $item->quantity,
            ]);
        }

        InventoryTransaction::create([
            'product_id' => $item->product_id,
            'warehouse_id' => $transfer->to_warehouse_id,
            'type' => 'transfer',
            'quantity' => $item->quantity,
            'reference' => "transfer:{$transfer->id}:in",
            'user_id' => $userId,
        ]);
    }

    private function moveIngredientStock(StockTransfer $transfer, IngredientTransferItem $item, int $userId): void
    {
        $from = IngredientInventoryItem::query()
            ->where('ingredient_id', $item->ingredient_id)
            ->where('warehouse_id', $transfer->from_warehouse_id)
            ->lockForUpdate()
            ->first();

        if (!$from || $from->quantity < $item->quantity) {
            throw new \Exception("Insufficient stock in source warehouse for ingredient {$item->ingredient_id}");
        }

        $from->decrement('quantity', $item->quantity);

        IngredientTransaction::create([
            'ingredient_id' => $item->ingredient_id,
            'warehouse_id' => $transfer->from_warehouse_id,
            'type' => 'transfer',
            'quantity' => $item->quantity,
            'reference' => "transfer:{$transfer->id}:out",
            'user_id' => $userId,
        ]);

        $to = IngredientInventoryItem::query()
            ->where('ingredient_id', $item->ingredient_id)
            ->where('warehouse_id', $transfer->to_warehouse_id)
            ->lockForUpdate()
            ->first();

        if ($to) {
            $to->increment('quantity', $item->quantity);
        } else {
            IngredientInventoryItem::create([
                'ingredient_id' => $item->ingredient_id,
                'warehouse_id' => $transfer->to_warehouse_id,
                'quantity' => $item->quantity,
            ]);
        }

        IngredientTransaction::create([
            'ingredient_id' => $item->ingredient_id,
            'warehouse_id' => $transfer->to_warehouse_id,
            'type' => 'transfer',
            'quantity' => $item->quantity,
            'reference' => "transfer:{$transfer->id}:in",
            'user_id' => $userId,
        ]);
    }

    /**
     * Receive a single transfer line for a partial/line-by-line receipt,
     * distinct from receiveTransfer()'s all-or-nothing move (left untouched
     * and still callable). The FULL sent quantity leaves the source
     * warehouse (goods left custody the moment they were sent — deferred to
     * receipt time rather than transfer creation, per the "no stock movement
     * until send/receive" decision), but only $receivedBaseQty lands on the
     * destination. A shortfall therefore sits in neither ledger — exactly
     * the "does not return to main store automatically" behaviour the
     * discrepancy resolution flow depends on — and opens a
     * TransferDiscrepancy plus a manager notification instead of blocking
     * receipt.
     */
    public function receiveTransferLine(StockTransferItem|IngredientTransferItem $item, float $receivedBaseQty, int $receivedByUserId): StockTransferItem|IngredientTransferItem
    {
        return DB::transaction(function () use ($item, $receivedBaseQty, $receivedByUserId) {
            $item = $item->fresh();
            $transfer = $item->transfer;

            if (! $item->isPending()) {
                throw new \Exception('This transfer line has already been received.');
            }

            if ($receivedBaseQty < 0 || $receivedBaseQty > (float) $item->quantity) {
                throw new \Exception('Received quantity cannot exceed the quantity sent.');
            }

            $isIngredient = $item instanceof IngredientTransferItem;
            $sentQty = (float) $item->quantity;

            if ($isIngredient) {
                $this->debitIngredientSource($transfer, $item->ingredient_id, $sentQty, $receivedByUserId);
            } else {
                $this->debitProductSource($transfer, $item->product_id, $sentQty, $receivedByUserId);
            }

            if ($receivedBaseQty > 0) {
                if ($isIngredient) {
                    $this->creditIngredientDestination($transfer, $item->ingredient_id, $receivedBaseQty, $receivedByUserId);
                } else {
                    $this->creditProductDestination($transfer, $item->product_id, $receivedBaseQty, $receivedByUserId);
                }
            }

            $shortfall = round((float) $item->quantity - $receivedBaseQty, 2);
            $outcome = $shortfall <= 0 ? 'received_full' : ($receivedBaseQty > 0 ? 'received_short' : 'rejected');

            $item->update([
                'received_quantity' => $receivedBaseQty,
                'outcome' => $outcome,
                'received_by' => $receivedByUserId,
                'received_at' => now(),
            ]);

            if ($shortfall > 0) {
                $discrepancy = TransferDiscrepancy::create([
                    'stock_transfer_item_id' => $isIngredient ? null : $item->id,
                    'ingredient_transfer_item_id' => $isIngredient ? $item->id : null,
                    'missing_base_qty' => $shortfall,
                ]);

                $this->notifyManagersOfDiscrepancy($transfer, $item, $discrepancy);
            }

            $transfer->update([
                'status' => $transfer->allLinesResolved() ? 'received' : 'partially_received',
            ]);

            return $item->fresh();
        });
    }

    private function debitProductSource(StockTransfer $transfer, int $productId, float $qty, int $userId): void
    {
        $from = InventoryItem::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $transfer->from_warehouse_id)
            ->lockForUpdate()
            ->first();

        if (! $from || $from->quantity < $qty) {
            $name = Product::find($productId)?->name ?? "product #{$productId}";

            throw new \Exception("Cannot receive — the source warehouse no longer has enough {$name} to cover this transfer. Ask the storekeeper to record a procurement for it before retrying.");
        }

        $from->decrement('quantity', $qty);

        InventoryTransaction::create([
            'product_id' => $productId,
            'warehouse_id' => $transfer->from_warehouse_id,
            'type' => 'transfer',
            'quantity' => $qty,
            'reference' => "transfer:{$transfer->id}:out",
            'user_id' => $userId,
        ]);
    }

    private function debitIngredientSource(StockTransfer $transfer, int $ingredientId, float $qty, int $userId): void
    {
        $from = IngredientInventoryItem::query()
            ->where('ingredient_id', $ingredientId)
            ->where('warehouse_id', $transfer->from_warehouse_id)
            ->lockForUpdate()
            ->first();

        if (! $from || $from->quantity < $qty) {
            $name = Ingredient::find($ingredientId)?->name ?? "ingredient #{$ingredientId}";

            throw new \Exception("Cannot receive — the source warehouse no longer has enough {$name} to cover this transfer. Ask the storekeeper to record a procurement for it before retrying.");
        }

        $from->decrement('quantity', $qty);

        IngredientTransaction::create([
            'ingredient_id' => $ingredientId,
            'warehouse_id' => $transfer->from_warehouse_id,
            'type' => 'transfer',
            'quantity' => $qty,
            'reference' => "transfer:{$transfer->id}:out",
            'user_id' => $userId,
        ]);
    }

    private function creditProductDestination(StockTransfer $transfer, int $productId, float $qty, int $userId): void
    {
        $to = InventoryItem::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $transfer->to_warehouse_id)
            ->lockForUpdate()
            ->first();

        if ($to) {
            $to->increment('quantity', $qty);
        } else {
            InventoryItem::create([
                'product_id' => $productId,
                'warehouse_id' => $transfer->to_warehouse_id,
                'quantity' => $qty,
            ]);
        }

        InventoryTransaction::create([
            'product_id' => $productId,
            'warehouse_id' => $transfer->to_warehouse_id,
            'type' => 'transfer',
            'quantity' => $qty,
            'reference' => "transfer:{$transfer->id}:in",
            'user_id' => $userId,
        ]);
    }

    private function creditIngredientDestination(StockTransfer $transfer, int $ingredientId, float $qty, int $userId): void
    {
        $to = IngredientInventoryItem::query()
            ->where('ingredient_id', $ingredientId)
            ->where('warehouse_id', $transfer->to_warehouse_id)
            ->lockForUpdate()
            ->first();

        if ($to) {
            $to->increment('quantity', $qty);
        } else {
            IngredientInventoryItem::create([
                'ingredient_id' => $ingredientId,
                'warehouse_id' => $transfer->to_warehouse_id,
                'quantity' => $qty,
            ]);
        }

        IngredientTransaction::create([
            'ingredient_id' => $ingredientId,
            'warehouse_id' => $transfer->to_warehouse_id,
            'type' => 'transfer',
            'quantity' => $qty,
            'reference' => "transfer:{$transfer->id}:in",
            'user_id' => $userId,
        ]);
    }

    private function notifyManagersOfDiscrepancy(StockTransfer $transfer, StockTransferItem|IngredientTransferItem $item, TransferDiscrepancy $discrepancy): void
    {
        $itemName = $item instanceof IngredientTransferItem
            ? $item->ingredient?->name
            : $item->product?->name;

        $managers = User::whereHas('roles', function ($q) {
            $q->whereIn('name', ['manager', 'admin', 'super_admin']);
        })->get();

        foreach ($managers as $manager) {
            Notification::make()
                ->title('Transfer received short')
                ->body("Transfer {$transfer->transfer_number}, {$itemName}: short by {$discrepancy->missing_base_qty}.")
                ->warning()
                ->sendToDatabase($manager);
        }
    }

    /**
     * Reverse an open discrepancy's missing quantity back onto Main Store
     * (the transfer's source warehouse) — used when the shortfall turns out
     * to have never left the store (miscount, found later).
     */
    public function reverseDiscrepancyToStore(TransferDiscrepancy $discrepancy, int $resolvedByUserId, string $note): TransferDiscrepancy
    {
        return DB::transaction(function () use ($discrepancy, $resolvedByUserId, $note) {
            if (! $discrepancy->isOpen()) {
                throw new \Exception('This discrepancy has already been resolved.');
            }

            $item = $discrepancy->stockTransferItem ?? $discrepancy->ingredientTransferItem;
            $transfer = $item->transfer;
            $qty = (float) $discrepancy->missing_base_qty;

            if ($discrepancy->ingredient_transfer_item_id) {
                $from = IngredientInventoryItem::query()
                    ->where('ingredient_id', $item->ingredient_id)
                    ->where('warehouse_id', $transfer->from_warehouse_id)
                    ->lockForUpdate()
                    ->first();

                $from ? $from->increment('quantity', $qty) : IngredientInventoryItem::create([
                    'ingredient_id' => $item->ingredient_id,
                    'warehouse_id' => $transfer->from_warehouse_id,
                    'quantity' => $qty,
                ]);

                $reversalTransaction = IngredientTransaction::create([
                    'ingredient_id' => $item->ingredient_id,
                    'warehouse_id' => $transfer->from_warehouse_id,
                    'type' => 'transfer_reversal_in',
                    'quantity' => $qty,
                    'reference' => "discrepancy:{$discrepancy->id}:reversal",
                    'user_id' => $resolvedByUserId,
                ]);

                $discrepancy->update(['reversal_ingredient_transaction_id' => $reversalTransaction->id]);
            } else {
                $from = InventoryItem::query()
                    ->where('product_id', $item->product_id)
                    ->where('warehouse_id', $transfer->from_warehouse_id)
                    ->lockForUpdate()
                    ->first();

                $from ? $from->increment('quantity', $qty) : InventoryItem::create([
                    'product_id' => $item->product_id,
                    'warehouse_id' => $transfer->from_warehouse_id,
                    'quantity' => $qty,
                ]);

                $reversalTransaction = InventoryTransaction::create([
                    'product_id' => $item->product_id,
                    'warehouse_id' => $transfer->from_warehouse_id,
                    'type' => 'transfer_reversal_in',
                    'quantity' => $qty,
                    'reference' => "discrepancy:{$discrepancy->id}:reversal",
                    'user_id' => $resolvedByUserId,
                ]);

                $discrepancy->update(['reversal_inventory_transaction_id' => $reversalTransaction->id]);
            }

            $discrepancy->update([
                'status' => 'reversed_to_store',
                'resolved_by' => $resolvedByUserId,
                'resolved_at' => now(),
                'resolution_note' => $note,
            ]);

            return $discrepancy->fresh();
        });
    }

    /**
     * Write off an open discrepancy as genuinely missing — no stock
     * movement, just a resolved audit record.
     */
    public function writeOffDiscrepancy(TransferDiscrepancy $discrepancy, int $resolvedByUserId, string $note): TransferDiscrepancy
    {
        if (! $discrepancy->isOpen()) {
            throw new \Exception('This discrepancy has already been resolved.');
        }

        $discrepancy->update([
            'status' => 'written_off_missing',
            'resolved_by' => $resolvedByUserId,
            'resolved_at' => now(),
            'resolution_note' => $note,
        ]);

        return $discrepancy->fresh();
    }
}
