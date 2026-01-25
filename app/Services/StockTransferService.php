<?php

namespace App\Services;

use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockTransferService
{
    /**
     * Create a new stock transfer (storekeeper initiates)
     * $items = [ ['product_id' => 1, 'quantity' => 5], ... ]
     */
    public function createTransfer(int $fromWarehouseId, int $toWarehouseId, int $userId, array $items): StockTransfer
    {
        return DB::transaction(function () use ($fromWarehouseId, $toWarehouseId, $userId, $items) {
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

            return $transfer;
        });
    }

    /**
     * Mark transfer as received: moves stock from main to destination.
     * Ensures from_warehouse has stock before moving.
     */
    public function receiveTransfer(StockTransfer $transfer, int $receivedByUserId): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $receivedByUserId) {
            if ($transfer->status !== 'pending' && $transfer->status !== 'sent') {
                throw new \Exception('Transfer cannot be received.');
            }

            foreach ($transfer->items as $item) {
                // Decrement from source
                $from = DB::table('inventory_items')
                    ->where('product_id', $item->product_id)
                    ->where('warehouse_id', $transfer->from_warehouse_id)
                    ->first();

                if (!$from || $from->quantity < $item->quantity) {
                    throw new \Exception("Insufficient stock in source warehouse for product {$item->product_id}");
                }

                DB::table('inventory_items')
                    ->where('product_id', $item->product_id)
                    ->where('warehouse_id', $transfer->from_warehouse_id)
                    ->decrement('quantity', $item->quantity);

                // Increment destination (create record if missing)
                $exists = DB::table('inventory_items')
                    ->where('product_id', $item->product_id)
                    ->where('warehouse_id', $transfer->to_warehouse_id)
                    ->exists();

                if ($exists) {
                    DB::table('inventory_items')
                        ->where('product_id', $item->product_id)
                        ->where('warehouse_id', $transfer->to_warehouse_id)
                        ->increment('quantity', $item->quantity);
                } else {
                    DB::table('inventory_items')->insert([
                        'product_id' => $item->product_id,
                        'warehouse_id' => $transfer->to_warehouse_id,
                        'quantity' => $item->quantity,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $transfer->update(['status' => 'received']);

            return $transfer;
        });
    }
}
