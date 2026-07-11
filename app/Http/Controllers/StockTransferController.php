<?php

namespace App\Http\Controllers;

use App\Models\StockTransfer;
use App\Services\StockTransferService;
use Illuminate\Http\Request;

class StockTransferController extends Controller
{
    protected StockTransferService $service;

    public function __construct(StockTransferService $service)
    {
        $this->service = $service;
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (! $user->hasAnyRole(['storekeeper','super_admin'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'from_warehouse_id' => 'required|integer',
            'to_warehouse_id' => 'required|integer',
            'items' => 'required_without:ingredient_items|array',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required_without:items.*.entered_qty|nullable|numeric|min:0.01',
            'items.*.entered_qty' => 'nullable|numeric|min:0.01',
            'items.*.entered_unit' => 'nullable|in:purchase_unit,base_unit',
            'ingredient_items' => 'required_without:items|array',
            'ingredient_items.*.ingredient_id' => 'required|integer',
            'ingredient_items.*.quantity' => 'required_without:ingredient_items.*.entered_qty|nullable|numeric|min:0.01',
            'ingredient_items.*.entered_qty' => 'nullable|numeric|min:0.01',
            'ingredient_items.*.entered_unit' => 'nullable|in:purchase_unit,base_unit',
        ]);

        $transfer = $this->service->createTransfer(
            $data['from_warehouse_id'],
            $data['to_warehouse_id'],
            $user->id,
            $data['items'] ?? [],
            $data['ingredient_items'] ?? []
        );

        return response()->json($transfer->load(['items', 'ingredientItems']));
    }

    public function send(StockTransfer $stockTransfer, Request $request)
    {
        $user = $request->user();
        if (! $user->hasAnyRole(['storekeeper','super_admin'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($stockTransfer->status !== 'pending') {
            return response()->json(['message' => 'Transfer cannot be sent'], 422);
        }

        $stockTransfer->update(['status' => 'sent']);

        return response()->json($stockTransfer->fresh()->load(['items', 'ingredientItems']));
    }

    public function receive(StockTransfer $stockTransfer, Request $request)
    {
        $user = $request->user();
        // allow kitchen/bar staff to receive; also storekeeper can receive
        if (! ($user->hasAnyRole(['storekeeper','chef','bartender']))) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $transfer = $this->service->receiveTransfer($stockTransfer, $user->id);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($transfer->fresh()->load('items'));
    }

    public function bulkReceive(Request $request)
    {
        $user = $request->user();
        // allow kitchen/bar staff to receive; also storekeeper can receive
        if (! ($user->hasAnyRole(['storekeeper','chef','bartender']))) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'transfer_ids' => 'required|array|min:1',
            'transfer_ids.*' => 'required|integer|exists:stock_transfers,id',
        ]);

        $results = [];
        $errors = [];

        foreach ($data['transfer_ids'] as $transferId) {
            try {
                $transfer = StockTransfer::findOrFail($transferId);
                $receivedTransfer = $this->service->receiveTransfer($transfer, $user->id);
                $results[] = [
                    'id' => $transferId,
                    'status' => 'received',
                    'transfer_number' => $receivedTransfer->transfer_number,
                ];
            } catch (\Exception $e) {
                $errors[] = [
                    'id' => $transferId,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'results' => $results,
            'errors' => $errors,
            'total_processed' => count($results) + count($errors),
            'successful' => count($results),
            'failed' => count($errors),
        ]);
    }

    public function productQuantity($warehouseId, $productId)
    {
        $qty = \DB::table('inventory_items')
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->value('quantity');

        return response()->json(['quantity' => (float) $qty]);
    }

    public function ingredientQuantity($warehouseId, $ingredientId)
    {
        $qty = \DB::table('ingredient_inventory_items')
            ->where('warehouse_id', $warehouseId)
            ->where('ingredient_id', $ingredientId)
            ->value('quantity');

        return response()->json(['quantity' => (float) $qty]);
    }
}
