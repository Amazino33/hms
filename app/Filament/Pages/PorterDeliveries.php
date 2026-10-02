<?php

namespace App\Filament\Pages;

use App\Models\Order;
use App\Services\PermissionService;
use App\Services\PorterDeliveryService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use UnitEnum;

class PorterDeliveries extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static string|UnitEnum|null $navigationGroup = 'Hotel';

    protected static ?string $navigationLabel = 'Porter Deliveries';

    protected static ?string $title = 'Porter Deliveries';

    protected string $view = 'filament.pages.porter-deliveries';

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    public function getViewData(): array
    {
        // Guest room orders (Phase 5) are delivered from reception's Room
        // Orders page instead — one delivery flow each, never both.
        $fromGuests = \App\Models\GuestRequestItem::query()->whereNotNull('order_id')->select('order_id');

        return [
            'readyForPickup' => Order::with(['items', 'booking.room', 'booking.guest'])
                ->where('status', 'ready')
                ->whereNotNull('booking_id')
                ->whereNull('picked_up_at')
                ->whereNotIn('id', $fromGuests)
                ->oldest()
                ->get(),
            'inTransit' => Order::with(['items', 'booking.room', 'booking.guest', 'pickedUpBy'])
                ->where('status', 'ready')
                ->whereNotNull('booking_id')
                ->whereNotNull('picked_up_at')
                ->whereNotIn('id', $fromGuests)
                ->oldest()
                ->get(),
        ];
    }

    public function pickUp(int $orderId): void
    {
        try {
            (new PorterDeliveryService)->pickUp(Order::findOrFail($orderId), auth()->user());

            Notification::make()->title('Order picked up')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not pick up')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    public function confirmDelivered(int $orderId): void
    {
        try {
            (new PorterDeliveryService)->confirmDelivered(Order::findOrFail($orderId), auth()->user());

            Notification::make()->title('Delivery confirmed')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not confirm delivery')->body($e->getMessage())->danger()->persistent()->send();
        }
    }
}
