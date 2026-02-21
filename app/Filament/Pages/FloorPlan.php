<?php

namespace App\Filament\Pages;

use App\Services\PermissionService;
use App\Models\Company;
use App\Models\Table;
use App\Models\Order;
use Filament\Actions\Action;
use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

class FloorPlan extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map';
    protected static string|UnitEnum|null $navigationGroup = 'Restaurant Management';
    protected static ?string $title = 'Live Floor Monitor';
    
    protected string $view = 'filament.pages.floor-plan';

    // --- Print Receipt Modal State ---
    public bool $showPrintModal = false;
    public ?int $printTableId = null;
    public string $printTableName = '';
    public array $printItems = [];
    public float $printTotal = 0;

    // Company info for receipt header
    public string $printCompanyName = '';
    public string $printCompanyAddress = '';
    public string $printCompanyPhone = '';
    public string $printCompanyLogo = '';

    // Refresh data for the view
    public function getViewData(): array
    {
        return [
            'tables' => Table::with(['orders' => function ($q) {
                $q->whereIn('status', ['pending', 'preparing', 'ready', 'served']) // Include active orders
                    ->with(['items.product', 'user']) // Include order items, products, and user
                    ->latest();
            }])->get(),
        ];
    }

    /**
     * Open the print-receipt confirmation modal for a given table.
     * Fetches all active order items so the cashier can verify before printing.
     */
    public function openPrintModal(int $tableId): void
    {
        $table = Table::find($tableId);
        if (!$table) {
            return;
        }

        $orders = Order::where('table_id', $tableId)
            ->whereIn('status', ['pending', 'preparing', 'ready', 'served'])
            ->with('items.product')
            ->get();

        $items = [];
        $total = 0;

        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $key = $item->product_id ?: ('mi_' . $item->menu_item_id);
                if (isset($items[$key])) {
                    $items[$key]['quantity'] += $item->quantity;
                } else {
                    $items[$key] = [
                        'name'     => $item->product_name,
                        'price'    => (float) $item->unit_price,
                        'quantity' => $item->quantity,
                    ];
                }
                $total += $item->unit_price * $item->quantity;
            }
        }

        $this->printTableId   = $tableId;
        $this->printTableName = $table->name;
        $this->printItems     = array_values($items);
        $this->printTotal     = $total;

        $company = Company::first();
        $this->printCompanyName    = $company?->name ?? '';
        $this->printCompanyAddress = $company?->address ?? '';
        $this->printCompanyPhone   = $company?->phone_number ?? '';
        $this->printCompanyLogo    = $company?->logo_path ? asset('storage/' . $company->logo_path) : '';

        $this->showPrintModal = true;
    }

    /**
     * Close the print-receipt confirmation modal.
     */
    public function closePrintModal(): void
    {
        $this->showPrintModal = false;
        $this->printTableId   = null;
        $this->printTableName = '';
        $this->printItems     = [];
        $this->printTotal     = 0;
        $this->printCompanyName    = '';
        $this->printCompanyAddress = '';
        $this->printCompanyPhone   = '';
        $this->printCompanyLogo    = '';
    }

    // Action: Clear a table directly from this screen
    public function clearTable($tableId)
    {
        $table = Table::find($tableId);
        if ($table) {
            $table->update(['status' => 'available']);
            // Optional: Close any pending orders if you want to force it
        }
        
        $this->dispatch('notify', 'Table Cleared'); // Simple notification
    }

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }
}