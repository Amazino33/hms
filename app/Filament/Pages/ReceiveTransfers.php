<?php

namespace App\Filament\Pages;

use App\Models\IngredientTransferItem;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Services\PermissionService;
use App\Services\StockTransferService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class ReceiveTransfers extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-inbox';

    protected static ?string $navigationLabel = 'Receive Transfers';

    protected string $view = 'filament.pages.receive-transfers';

    // Defer loading of transfers list (keeps initial admin pages fast)
    public bool $ready = false;

    public function load(): void
    {
        $this->ready = true;
    }

    public function getViewData(): array
    {
        $user = Auth::user();
        $warehouseId = null;
        $warehouseName = null;

        // Resolved through StockTransferService (which defers to
        // InventoryService) rather than re-deriving "first/second consumer
        // warehouse" here — this page used to carry its own copy of that
        // positional logic, free to drift away from the answer the rest of
        // the app gets to the same question.
        $custodianRole = StockTransferService::custodianRoleFor($user);

        if ($custodianRole) {
            $warehouseId = StockTransferService::custodianWarehouseId($custodianRole);
            $warehouseName = $warehouseId ? \App\Models\WareHouse::find($warehouseId)?->name : null;
        }

        if (! $this->ready) {
            // Return lightweight default data while client-side load triggers the full retrieval
            return [
                'transfers' => collect(),
                'pastTransfers' => collect(),
                'warehouseId' => $warehouseId,
                'warehouseName' => $warehouseName,
            ];
        }

        $pastPage = (int) request()->get('past_page', 1);

        if ($user->hasRole('storekeeper') || $user->hasRole('super_admin')) {
            // Storekeeper can see all transfers regardless of warehouse
            $transfers = Cache::remember('receive_transfers:all', 5, fn () => StockTransfer::with(['items.product', 'items.receivedBy', 'ingredientItems.ingredient', 'ingredientItems.receivedBy', 'fromWarehouse', 'toWarehouse', 'user'])
                ->whereIn('status', ['pending', 'sent', 'partially_received'])
                ->latest()
                ->get()
            );
            $pastTransfers = Cache::remember("receive_transfers:past:all:{$pastPage}", 5, fn () => StockTransfer::with(['items.product', 'items.receivedBy', 'ingredientItems.ingredient', 'ingredientItems.receivedBy', 'fromWarehouse', 'toWarehouse', 'user'])
                ->where('status', 'received')
                ->latest()
                ->paginate(10, ['*'], 'past_page', $pastPage)
            );

            return [
                'transfers' => $transfers,
                'pastTransfers' => $pastTransfers,
                'warehouseId' => 'all',
                'warehouseName' => 'All Warehouses',
            ];
        }

        // If no role-based warehouse and user has assigned warehouse, use it
        if (! $warehouseId && $user->warehouse) {
            $warehouseId = $user->warehouse->id;
            $warehouseName = $user->warehouse->name;
        }

        // If still no warehouse, show the error
        if (! $warehouseId) {
            return [
                'error' => 'No Warehouse Assigned - Your role is not mapped to a warehouse. Contact an administrator.',
                'transfers' => collect(),
                'pastTransfers' => collect(),
                'warehouseId' => null,
                'warehouseName' => null,
            ];
        }

        $transfers = collect();
        $pastTransfers = collect();
        if ($warehouseId) {
            $cacheKey = "receive_transfers:wh:{$warehouseId}";
            $transfers = Cache::remember($cacheKey, 5, fn () => StockTransfer::where('to_warehouse_id', $warehouseId)
                ->whereIn('status', ['pending', 'sent', 'partially_received'])
                ->with(['items.product', 'items.receivedBy', 'ingredientItems.ingredient', 'ingredientItems.receivedBy', 'fromWarehouse', 'toWarehouse', 'user'])
                ->latest()
                ->get()
            );

            $pastTransfers = Cache::remember("receive_transfers:past:wh:{$warehouseId}:{$pastPage}", 5, fn () => StockTransfer::where('to_warehouse_id', $warehouseId)
                ->where('status', 'received')
                ->with(['items.product', 'items.receivedBy', 'ingredientItems.ingredient', 'ingredientItems.receivedBy', 'fromWarehouse', 'toWarehouse', 'user'])
                ->latest()
                ->paginate(10, ['*'], 'past_page', $pastPage)
            );
        }

        return [
            'transfers' => $transfers,
            'pastTransfers' => $pastTransfers,
            'warehouseId' => $warehouseId,
            'warehouseName' => $warehouseName,
        ];
    }

    /**
     * Receive a single transfer line for a partial/line-by-line receipt —
     * the primary receive path now. The whole-transfer bulk-receive action
     * (StockTransferController::bulkReceive, calling the all-or-nothing
     * receiveTransfer()) stays available for full receipts, and goes
     * through the same custodian shift/warehouse gate.
     */
    public function receiveLine(int $itemId, string $type, mixed $receivedQty): void
    {
        $user = Auth::user();

        if (! $user->hasAnyRole(['storekeeper', 'chef', 'bartender', 'super_admin'])) {
            Notification::make()->danger()->title('Permission denied')->persistent()->send();

            return;
        }

        $item = $type === 'ingredient'
            ? IngredientTransferItem::findOrFail($itemId)
            : StockTransferItem::findOrFail($itemId);

        try {
            app(StockTransferService::class)->receiveTransferLine($item, (float) $receivedQty, $user->id);
            Notification::make()->success()->title('Line received')->send();
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Could not receive line')->body($e->getMessage())->persistent()->send();

            return;
        }

        Cache::forget('receive_transfers:all');
        Cache::forget("receive_transfers:wh:{$item->transfer->to_warehouse_id}");

        // A line receipt can flip the whole transfer to 'received' — clear
        // every cached past-transfers page rather than guessing which one
        // it would now land on.
        for ($page = 1; $page <= 20; $page++) {
            Cache::forget("receive_transfers:past:all:{$page}");
            Cache::forget("receive_transfers:past:wh:{$item->transfer->to_warehouse_id}:{$page}");
        }
    }

    /**
     * Page permission decides whether the role may ever receive; the shift
     * decides whether THIS person may right now. An off-shift bartender or
     * chef is denied outright — not shown a read-only list — because
     * receiving credits the warehouse the instant it happens, so anything
     * they do here lands on the count of whoever is actually on duty. The
     * page disappears from the sidebar with them.
     *
     * Storekeeper/super_admin (and any other role a manager grants this
     * page to) hold no custodian shift and are unaffected.
     */
    public static function canAccess(): bool
    {
        if (! PermissionService::canAccessPage(self::class)) {
            return false;
        }

        $user = Auth::user();
        $custodianRole = StockTransferService::custodianRoleFor($user);

        if (! $custodianRole) {
            return true;
        }

        return StockTransferService::activeCustodianShiftFor($user, $custodianRole) !== null;
    }
}
