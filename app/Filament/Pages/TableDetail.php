<?php

namespace App\Filament\Pages;

use App\Models\Table;
use App\Models\Order;
use App\Services\PermissionService;
use Filament\Pages\Page;
use Illuminate\Http\Request;
use BackedEnum;

class TableDetail extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-eye';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.table-detail';

    protected static ?string $title = 'Table Details';

    protected static ?string $slug = 'table-detail';

    public static function canAccess(): bool
    {
        // Had no gate at all before — shouldRegisterNavigation only hides
        // the nav link, not the route itself. Gated the same as FloorPlan,
        // since this page is always reached from there.
        return PermissionService::canAccessPage(self::class);
    }

    public $table;
    public $order;
    public $orderItems = [];
    public $orders;

    public function mount(Request $request)
    {
        $tableId = $request->query('table_id');

        if (!$tableId) {
            return redirect('/admin/floor-plan');
        }

        $this->table = Table::find($tableId);

        if (!$this->table) {
            return redirect('/admin/floor-plan');
        }

        $this->loadOrders($tableId);
    }

    protected function loadOrders($tableId): void
    {
        // Get all active orders for the table created by the current user
        $this->orderItems = collect();
        $orders = Order::where('table_id', $tableId)
            ->where('user_id', auth()->id()) // Only orders created by current user
            ->whereIn('status', ['pending', 'preparing', 'ready', 'served'])
            ->with('items.product')
            ->get();

        foreach ($orders as $order) {
            $this->orderItems = $this->orderItems->merge($order->items);
        }

        $this->orders = $orders;

        // Set order to the first one for compatibility, but we'll use orderItems
        $this->order = $orders->first();
    }

    /**
     * Waiter (or a supervisor) confirms an order has been picked up/carried
     * to the table. This is the only path that moves an order from 'ready'
     * to 'served' — payment is blocked until this happens.
     */
    public function confirmServed(int $orderId): void
    {
        $order = Order::find($orderId);

        if (!$order || $order->table_id !== $this->table->id) {
            \Filament\Notifications\Notification::make()
                ->title('Order not found')
                ->danger()
                ->send();
            return;
        }

        $isOwner = $order->user_id === auth()->id();
        $isSupervisor = auth()->user()->hasRole(['manager', 'admin', 'super_admin']);

        if (!$isOwner && !$isSupervisor) {
            \Filament\Notifications\Notification::make()
                ->title('Not your order')
                ->body('Only the waiter who took this order (or a supervisor) can confirm it as served.')
                ->danger()
                ->send();
            return;
        }

        if ($order->status !== 'ready') {
            \Filament\Notifications\Notification::make()
                ->title('Order is not ready yet')
                ->body('This order must be marked ready by the kitchen/bar before it can be confirmed served.')
                ->warning()
                ->send();
            return;
        }

        $order->update([
            'status' => 'served',
            'served_at' => now(),
        ]);

        \Filament\Notifications\Notification::make()
            ->title('Order marked as served')
            ->success()
            ->send();

        $this->loadOrders($this->table->id);
    }

    public function cancelOrder()
    {
        // Find all pending orders for this table created by the current user and cancel them
        $orders = Order::where('table_id', $this->table->id)
            ->where('user_id', auth()->id()) // Only orders created by current user
            ->where('status', 'pending')
            ->get();

        if ($orders->isEmpty()) {
            \Filament\Notifications\Notification::make()
                ->title('No active orders to cancel')
                ->warning()
                ->send();
            return;
        }

        // Update order statuses to cancelled
        foreach ($orders as $order) {
            $order->update(['status' => 'cancelled']);
        }

        // Set table status to available
        $this->table->update(['status' => 'available']);

        \Filament\Notifications\Notification::make()
            ->title('Order cancelled successfully')
            ->success()
            ->send();

        // Redirect back to floor plan
        return redirect('/admin/floor-plan');
    }

    public function getTitle(): string
    {
        return "Table {$this->table?->name} - Details";
    }
}