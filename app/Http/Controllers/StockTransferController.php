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
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|numeric|min:1',
        ]);

        $transfer = $this->service->createTransfer(
            $data['from_warehouse_id'],
            $data['to_warehouse_id'],
            $user->id,
            $data['items']
        );

        return response()->json($transfer->load('items'));
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

        return response()->json($stockTransfer->fresh()->load('items'));
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

    public function productQuantity($warehouseId, $productId)
    {
        $qty = \DB::table('inventory_items')
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->value('quantity');

        return response()->json(['quantity' => (int) $qty]);
    }
}
