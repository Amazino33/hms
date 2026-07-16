<?php

namespace App\Filament\Pages;

use App\Models\Booking;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Product;
use App\Models\Room;
use App\Services\PermissionService;
use App\Services\RoomOrderService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Http\Request;
use UnitEnum;

/**
 * A purpose-built, simpler product grid for room orders — not the POS grid
 * (resources/views/livewire/pos.blade.php), which is a single 2,700+ line
 * file with its own client-side cart and payment/checkout/returns logic
 * that's explicitly off-limits to touch. Cart state lives server-side in
 * a plain Livewire property here rather than Alpine, trading a little
 * snappiness for a much smaller, easier-to-test component — acceptable
 * for this screen's lower interaction volume.
 */
class RoomOrder extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static string|UnitEnum|null $navigationGroup = 'Hotel';

    protected static ?string $navigationLabel = 'Room Order';

    protected static ?string $title = 'Room Order';

    protected string $view = 'filament.pages.room-order';

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    public ?int $roomId = null;

    public ?string $roomNumber = null;

    public ?int $bookingId = null;

    public array $cart = [];

    public ?int $activeCategoryId = null;

    public string $search = '';

    public function mount(Request $request): void
    {
        $roomId = $request->query('room');

        if ($roomId) {
            $this->selectRoom((int) $roomId);
        }
    }

    public function checkedInBookings()
    {
        return Booking::with(['room', 'guest'])->where('status', 'checked_in')->get();
    }

    public function selectRoom(int $roomId): void
    {
        $booking = Booking::where('room_id', $roomId)->where('status', 'checked_in')->with('room')->first();

        if (! $booking) {
            Notification::make()->title('This room has no checked-in guest')->warning()->send();
            return;
        }

        $this->roomId = $roomId;
        $this->roomNumber = $booking->room->number;
        $this->bookingId = $booking->id;
        $this->cart = [];
    }

    public function changeRoom(): void
    {
        $this->roomId = null;
        $this->roomNumber = null;
        $this->bookingId = null;
        $this->cart = [];
    }

    public function categories()
    {
        return Category::orderBy('name')->get();
    }

    public function products()
    {
        $query = Product::where('is_active', true)->with('category');

        if ($this->search) {
            $query->where('name', 'like', "%{$this->search}%");
        } elseif ($this->activeCategoryId) {
            $query->where('category_id', $this->activeCategoryId);
        }

        return $query->orderBy('name')->limit(60)->get();
    }

    public function menuItems()
    {
        $query = MenuItem::where('available_for_sale', true)->with('category');

        if ($this->search) {
            $query->where('name', 'like', "%{$this->search}%");
        } elseif ($this->activeCategoryId) {
            $query->where('category_id', $this->activeCategoryId);
        }

        return $query->orderBy('name')->limit(60)->get();
    }

    public function addProductToCart(int $productId, string $name, float $price): void
    {
        $this->addToCart((string) $productId, $name, $price);
    }

    public function addMenuItemToCart(int $menuItemId, string $name, float $price): void
    {
        $this->addToCart('menu_' . $menuItemId, $name, $price);
    }

    private function addToCart(string $key, string $name, float $price): void
    {
        if (isset($this->cart[$key])) {
            $this->cart[$key]['quantity']++;
        } else {
            $this->cart[$key] = ['name' => $name, 'price' => $price, 'quantity' => 1];
        }
    }

    public function decrementCartLine(string $key): void
    {
        if (! isset($this->cart[$key])) {
            return;
        }

        $this->cart[$key]['quantity']--;

        if ($this->cart[$key]['quantity'] <= 0) {
            unset($this->cart[$key]);
        }
    }

    public function removeFromCart(string $key): void
    {
        unset($this->cart[$key]);
    }

    public function cartTotal(): float
    {
        return collect($this->cart)->sum(fn (array $i) => $i['price'] * $i['quantity']);
    }

    public function submitOrder(): void
    {
        if (! $this->roomId) {
            Notification::make()->title('Select a room first')->warning()->send();
            return;
        }

        if (empty($this->cart)) {
            Notification::make()->title('Cart is empty')->warning()->send();
            return;
        }

        try {
            $orders = (new RoomOrderService())->placeOrder($this->roomId, $this->cart, auth()->id());

            $this->dispatchTickets($orders);

            $this->cart = [];

            Notification::make()->title('Room order sent to kitchen/bar')->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could not place order')->body($e->getMessage())->danger()->persistent()->send();
        }
    }

    /**
     * One ticket per destination order — a mixed room order (e.g. a beer
     * for the bar and a plate for the kitchen in the same cart) splits
     * into separate Order rows already (OrderSplitter's station routing);
     * each station gets its own printed ticket, same as they'd expect
     * from a normal POS-placed order.
     */
    private function dispatchTickets(array $orders): void
    {
        $room = Room::find($this->roomId);

        foreach ($orders as $order) {
            $order->load('items');

            $this->dispatch('print-room-ticket', [
                'roomNumber' => $room?->number,
                'destination' => ucfirst($order->destination),
                'orderNumber' => $order->order_number,
                'items' => $order->items->map(fn ($item) => [
                    'name' => $item->product_name,
                    'quantity' => $item->quantity,
                ])->toArray(),
                'date' => now()->format('M j, Y g:i A'),
                'staff' => auth()->user()?->name,
            ]);
        }
    }

    public function getViewData(): array
    {
        return [
            'checkedInBookings' => $this->roomId ? collect() : $this->checkedInBookings(),
            'categories' => $this->roomId ? $this->categories() : collect(),
            'products' => $this->roomId ? $this->products() : collect(),
            'menuItems' => $this->roomId ? $this->menuItems() : collect(),
        ];
    }
}
