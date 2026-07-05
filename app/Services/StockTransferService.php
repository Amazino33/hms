<?php

namespace App\Services;

use App\Models\IngredientInventoryItem;
use App\Models\IngredientTransaction;
use App\Models\IngredientTransferItem;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockTransferService
{
    /**
     * Create a new stock transfer (storekeeper initiates)
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
                StockTransferItem::create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id' => $it['product_id'],
                    'quantity' => $it['quantity'],
                ]);
            }

            foreach ($ingredientItems as $it) {
                IngredientTransferItem::create([
                    'stock_transfer_id' => $transfer->id,
                    'ingredient_id' => $it['ingredient_id'],
                    'quantity' => $it['quantity'],
                ]);
            }

            return $transfer;
        });
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
}
