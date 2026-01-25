<?php

namespace App\Filament\Pages;

use App\Models\Table;
use App\Models\Order;
use Filament\Pages\Page;
use Illuminate\Http\Request;
use BackedEnum;

class TableDetail extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-eye';

    protected string $view = 'filament.pages.table-detail';

    protected static ?string $title = 'Table Details';

    protected static ?string $slug = 'table-detail';

    public $table;
    public $order;
    public $orderItems = [];

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

        // Get all active orders for the table
        $this->orderItems = collect();
        $orders = Order::where('table_id', $tableId)
            ->whereIn('status', ['pending', 'preparing', 'ready', 'served'])
            ->with('items.product')
            ->get();

        foreach ($orders as $order) {
            $this->orderItems = $this->orderItems->merge($order->items);
        }

        // Set order to the first one for compatibility, but we'll use orderItems
        $this->order = $orders->first();
    }

    public function getTitle(): string
    {
        return "Table {$this->table?->name} - Details";
    }
}