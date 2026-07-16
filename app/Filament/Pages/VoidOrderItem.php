<?php

namespace App\Filament\Pages;

use App\Models\OrderItem;
use App\Services\PermissionService;
use App\Services\UnreturnableVoidService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use UnitEnum;

/**
 * Manager-only. Explicitly NOT a return — no stock ever reverses here. This
 * is for an item that's already gone (comp, guest complaint, spillage after
 * serving) and just needs to stop counting against the waiter's remittance,
 * with a permanent reasoned record for reporting.
 *
 * Rebuilt as plain Blade/Alpine (search-pick, stepper, chip-select) rather
 * than Filament's Schema/Form API — every other operational surface is
 * custom Blade already; this was the one exception, which made it the one
 * surface the mobile pass's steppers/chips couldn't drop into without either
 * overriding Filament's own field rendering or doing this. UnreturnableVoidService
 * is still the sole authority on validity (reason code, quantity bounds) —
 * this class no longer duplicates that via form rules, since the service
 * already throws on anything invalid.
 */
class VoidOrderItem extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-refund';
    protected static string|UnitEnum|null $navigationGroup = 'Restaurant Management';
    protected static ?string $navigationLabel = 'Void Order Item';
    protected static ?string $title = 'Void Order Item (Supervisor Only)';
    protected string $view = 'filament.pages.void-order-item';

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    public string $search = '';

    public ?int $orderItemId = null;

    public int $quantity = 1;

    public ?string $reasonCode = null;

    public ?string $notes = null;

    public const REASON_OPTIONS = [
        'comp' => 'Comp (goodwill)',
        'complaint' => 'Guest Complaint',
        'loss' => 'Loss / Spillage',
        'other' => 'Other',
    ];

    /**
     * @return array<int, array{id: int, label: string, quantity: int}>
     */
    public function searchResults(): array
    {
        if (trim($this->search) === '') {
            return [];
        }

        return OrderItem::query()
            ->where('product_name', 'like', '%' . $this->search . '%')
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['served', 'ready', 'preparing', 'pending']))
            ->limit(20)
            ->get()
            ->map(fn (OrderItem $item) => [
                'id' => $item->id,
                'label' => "#{$item->order_id} — {$item->product_name} (qty {$item->quantity})",
                'quantity' => $item->quantity,
            ])
            ->all();
    }

    public function selectItem(int $itemId): void
    {
        $this->orderItemId = $itemId;
        $this->search = '';
        $this->quantity = 1;
    }

    public function clearSelection(): void
    {
        $this->orderItemId = null;
        $this->quantity = 1;
    }

    public function selectedItem(): ?OrderItem
    {
        return $this->orderItemId ? OrderItem::find($this->orderItemId) : null;
    }

    public function apply(): void
    {
        if (!$this->orderItemId) {
            Notification::make()->title('Choose an item first')->warning()->send();
            return;
        }

        if (!$this->reasonCode) {
            Notification::make()->title('Choose a reason first')->warning()->send();
            return;
        }

        try {
            $item = OrderItem::findOrFail($this->orderItemId);
            (new UnreturnableVoidService())->apply(
                $item,
                auth()->user(),
                $this->reasonCode,
                $this->quantity,
                $this->notes,
            );

            Notification::make()->title('Item voided')->success()->send();
            $this->reset(['orderItemId', 'quantity', 'reasonCode', 'notes', 'search']);
            $this->quantity = 1;
        } catch (\Exception $e) {
            Notification::make()->title('Could not void item')->body($e->getMessage())->danger()->persistent()->send();
        }
    }
}
