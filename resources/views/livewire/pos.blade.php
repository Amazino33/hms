<?php

use Livewire\Component;
use App\Models\Product;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Guest;
use Illuminate\Support\Facades\DB;
use Filament\Notifications\Notification;

new class extends Component {
    public $categories;
    public $tables;
    public $selectedTableId;
    public $activeCategoryId;
    public $currentOrderId = null;
    public $cart = [];
    public $total = 0;
    public $search = '';

    // Payment Properties
    public $showPaymentModal = false;
    public $paidAmount = 0;
    public $paymentMethod = 'cash';
    public $selectedGuestId = null;

    // 👇 NEW: Guest Creation Properties
    public $showGuestModal = false;
    public $newGuestName = '';
    public $newGuestPhone = '';

    public function clearSearch()
    {
        $this->search = '';
    }

    public function updatedSelectedTableId($value)
    {
        $this->cart = [];
        $this->currentOrderId = null;

        if (!$value) return;

        $existingOrder = \App\Models\Order::where('table_id', $value)
            ->where('status', 'pending')
            ->with('items')
            ->latest()
            ->first();

        if ($existingOrder) {
            $this->currentOrderId = $existingOrder->id;
            foreach ($existingOrder->items as $item) {
                $this->cart[$item->product_id] = [
                    'id' => $item->product_id,
                    'name' => $item->product_name,
                    'price' => $item->unit_price,
                    'quantity' => $item->quantity,
                    'image' => $item->product->image ?? null,
                ];
            }
            $this->updateTotal();
            Notification::make()->title('Order Resumed')->info()->send();
        }
    }

    public function updateTotal()
    {
        $this->total = 0;
        foreach ($this->cart as $item) {
            $this->total += $item['price'] * $item['quantity'];
        }
    }

    public function mount()
    {
        $this->categories = Category::has('products')->get();
        $this->activeCategoryId = $this->categories->first()?->id;

        $tables = \App\Models\Table::all();
        foreach ($tables as $table) {
            $hasPendingOrder = $table->orders()->where('status', 'pending')->exists();
            if ($hasPendingOrder && $table->status !== 'occupied') {
                $table->update(['status' => 'occupied']);
            } elseif (!$hasPendingOrder && $table->status !== 'available') {
                $table->update(['status' => 'available']);
            }
        }

        $this->tables = \App\Models\Table::with(['orders' => fn($q) => $q->where('status', 'pending')])->get();
        $this->selectedTableId = $this->tables->first()?->id;
    }

    public function addToCart($productId)
    {
        $product = Product::find($productId);
        if (isset($this->cart[$productId])) {
            $this->cart[$productId]['quantity']++;
        } else {
            $this->cart[$productId] = [
                'name' => $product->name,
                'price' => $product->price,
                'quantity' => 1,
            ];
        }
        $this->calculateTotal();
    }

    public function removeFromCart($productId)
    {
        unset($this->cart[$productId]);
        $this->calculateTotal();
    }

    public function calculateTotal()
    {
        $this->total = collect($this->cart)->sum(fn($item) => $item['price'] * $item['quantity']);
    }

    // --- PAYMENT LOGIC ---

    public function openPaymentModal()
    {
        if (empty($this->cart)) return;
        $this->calculateTotal();
        $this->paidAmount = $this->total; 
        $this->paymentMethod = 'cash';
        $this->selectedGuestId = null;
        $this->showPaymentModal = true;
    }

    // 👇 NEW: Logic to Save a Guest instantly
    public function saveNewGuest()
    {
        $this->validate([
            'newGuestName' => 'required|string|min:2',
            'newGuestPhone' => 'nullable|string|min:10', // Optional but recommended
        ]);

        // Create the guest
        $guest = Guest::create([
            'name' => $this->newGuestName,
            'phone' => $this->newGuestPhone,
            // 'email' => ... (if you need it)
        ]);

        // Auto-select the new guest in the dropdown
        $this->selectedGuestId = $guest->id;

        // Reset and Close Guest Modal
        $this->newGuestName = '';
        $this->newGuestPhone = '';
        $this->showGuestModal = false;

        Notification::make()->title('Guest Added')->success()->send();
    }

    public function processPayment()
    {
        $this->validate([
            'paidAmount' => 'required|numeric|min:0',
            'paymentMethod' => 'required',
            'selectedGuestId' => ($this->paidAmount < $this->total) ? 'required' : 'nullable',
        ], [
            'selectedGuestId.required' => 'Select a Guest to record debt.',
        ]);

        $tableId = $this->selectedTableId;
        
        DB::transaction(function () use ($tableId) {
            $orderStatus = ($this->paidAmount >= $this->total) ? 'paid' : 'partial';
            $tableStatus = 'available';

            $order = Order::updateOrCreate(
                ['id' => $this->currentOrderId],
                [
                    'order_number' => 'ORD-' . time(),
                    'total_amount' => $this->total,
                    'amount_paid' => $this->paidAmount,
                    'status' => $orderStatus,
                    'payment_method' => $this->paymentMethod,
                    'table_id' => $tableId,
                    'user_id' => auth()->id(),
                    'guest_id' => $this->selectedGuestId,
                ]
            );

            $order->items()->delete();
            foreach ($this->cart as $productId => $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $productId,
                    'product_name' => $item['name'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['price'],
                    'subtotal' => $item['price'] * $item['quantity'],
                ]);
                DB::table('inventory_items')->where('product_id', $productId)->where('warehouse_id', 2)->decrement('quantity', $item['quantity']);
            }

            \App\Models\Table::find($tableId)->update(['status' => $tableStatus]);

            $balance = $this->total - $this->paidAmount;
            $msg = $orderStatus === 'paid' ? "Paid: ₦" . number_format($this->paidAmount) : "Debt Recorded: ₦" . number_format($balance);
            Notification::make()->title($msg)->success()->send();
        });

        $this->showPaymentModal = false;
        $this->cart = [];
        $this->currentOrderId = null;
        $this->selectedTableId = null;
        $this->paidAmount = 0;
        $this->selectedGuestId = null;
    }

    // --- STANDARD CHECKOUT (Send to Kitchen) ---
    public function checkout($action = 'update')
    {
        // (Kept logic for standard update/send to kitchen)
        if (empty($this->cart)) return;
        $tableId = $this->selectedTableId;
        $tableName = \App\Models\Table::find($tableId)?->name ?? 'Unknown';

        DB::transaction(function () use ($tableId, $action, $tableName) {
            $order = Order::updateOrCreate(
                ['id' => $this->currentOrderId],
                ['order_number' => 'ORD-' . time(), 'total_amount' => $this->total, 'status' => 'pending', 'payment_method' => 'cash', 'table_id' => $tableId, 'user_id' => auth()->id()]
            );
            $order->items()->delete();
            
            // ... (Your existing item creation & notification logic) ...
            foreach ($this->cart as $productId => $item) {
                OrderItem::create(['order_id' => $order->id, 'product_id' => $productId, 'product_name' => $item['name'], 'quantity' => $item['quantity'], 'unit_price' => $item['price'], 'subtotal' => $item['price'] * $item['quantity']]);
                DB::table('inventory_items')->where('product_id', $productId)->where('warehouse_id', 2)->decrement('quantity', $item['quantity']);
            }
             // Notifications logic omitted for brevity, assuming kept from previous code
            \App\Models\Table::find($tableId)->update(['status' => 'occupied']);
        });

        $this->cart = [];
        $this->total = 0;
        Notification::make()->title('Order Updated')->success()->send();
    }

    public function with()
    {
        $query = Product::where('is_active', true)->withSum(['inventory as available_stock' => fn($q) => $q->where('warehouse_id', '!=', 3)], 'quantity');
        if (!empty($this->search)) $query->where(fn($q) => $q->where('name', 'like', "%{$this->search}%")->orWhere('sku', 'like', "%{$this->search}%"));
        elseif ($this->activeCategoryId) $query->where('category_id', $this->activeCategoryId);

        return ['products' => $query->get()];
    }
};
?>

<div class="grid grid-cols-12 gap-4 h-[calc(100vh-8rem)]">
    <div class="col-span-8 flex flex-col h-full bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="m-6">
            <div class="relative">
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search Item Name or Barcode..." class="w-full px-4 py-3 pl-12 text-lg border border-gray-300 dark:border-gray-600 rounded-xl shadow-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500" autofocus>
            </div>
        </div>
        <div class="flex overflow-x-auto p-2 bg-gray-50 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 space-x-2">
            @foreach($categories as $category)
                <button wire:click="$set('activeCategoryId', {{ $category->id }})" class="px-4 py-2 rounded-lg text-sm font-bold whitespace-nowrap transition-colors {{ $activeCategoryId === $category->id ? 'bg-amber-500 text-white' : 'bg-white dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-600' }}">{{ $category->name }}</button>
            @endforeach
        </div>
        <div class="flex-1 overflow-y-auto p-4 grid grid-cols-3 gap-4 content-start">
            @foreach($products as $product)
                <div wire:click="addToCart({{ $product->id }})" class="relative cursor-pointer bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 hover:border-amber-500 hover:shadow-md rounded-xl p-3 flex flex-col items-center justify-center text-center transition-all h-32 group">
                    <span class="absolute top-2 right-2 px-2 py-0.5 rounded-full text-xs font-bold {{ ($product->available_stock ?? 0) > 0 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">{{ $product->available_stock ?? 0 }} left</span>
                    <div class="font-bold text-gray-800 dark:text-gray-200 line-clamp-2">{{ $product->name }}</div>
                    <div class="text-amber-600 dark:text-amber-500 font-mono mt-1">₦{{ number_format($product->price) }}</div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="col-span-4 bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 flex flex-col h-full">
        <div class="p-4 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
            <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-1">Select Table</label>
            <select wire:model.live="selectedTableId" class="w-full p-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-800 text-gray-800 dark:text-gray-200 font-bold">
                <option value="">-- Select a Table --</option>
                @foreach($tables as $table)
                    <option value="{{ $table->id }}" class="{{ $table->orders->isNotEmpty() ? 'text-red-600 font-bold' : 'text-green-600' }}">{{ $table->name }} {{ $table->orders->isNotEmpty() ? '(Occupied)' : '(Free)' }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex-1 overflow-y-auto p-4 space-y-3">
            @foreach($cart as $id => $item)
                <div class="flex justify-between items-center border-b border-gray-200 dark:border-gray-700 pb-2">
                    <div class="flex-1">
                        <div class="font-bold text-sm text-gray-800 dark:text-gray-200">{{ $item['name'] }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">₦{{ $item['price'] }} x {{ $item['quantity'] }}</div>
                    </div>
                    <div class="font-mono font-bold text-gray-700 dark:text-gray-300">₦{{ number_format($item['price'] * $item['quantity']) }}</div>
                    <button wire:click="removeFromCart({{ $id }})" class="ml-3 text-red-500 hover:text-red-700">X</button>
                </div>
            @endforeach
        </div>
        <div class="p-4 bg-gray-50 dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700">
            <div class="flex justify-between text-xl font-bold mb-4 text-gray-900 dark:text-gray-100">
                <span>Total:</span><span>₦{{ number_format($total) }}</span>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <button wire:click="checkout('update')" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-lg flex flex-col items-center justify-center"><span class="text-sm">👨‍🍳 Update</span></button>
                <button wire:click="openPaymentModal" class="bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-4 rounded-lg flex flex-col items-center justify-center"><span class="text-sm">💰 Collect Cash</span></button>
            </div>
        </div>
    </div>

    @if($showPaymentModal)
    <div class="fixed inset-0 bg-black/50 z-[50] flex items-center justify-center p-4 backdrop-blur-sm">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md overflow-hidden border border-gray-200 dark:border-gray-700 relative">
            
            <div class="bg-gray-50 dark:bg-gray-800 p-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                <h3 class="text-xl font-bold text-gray-900 dark:text-white">💰 Checkout</h3>
                <button wire:click="$set('showPaymentModal', false)" class="text-gray-400 hover:text-red-500"><span class="text-2xl">&times;</span></button>
            </div>

            <div class="p-6 space-y-4">
                <div class="text-center mb-6">
                    <div class="text-sm text-gray-500 dark:text-gray-400 uppercase tracking-wider font-bold">Total Due</div>
                    <div class="text-4xl font-black text-gray-900 dark:text-white">₦{{ number_format($total) }}</div>
                </div>

                <div>
                    <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-1">Amount Received</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 font-bold">₦</span>
                        <input type="number" wire:model.live="paidAmount" class="w-full pl-8 pr-4 py-3 text-lg font-bold border rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:text-white" placeholder="0.00">
                    </div>
                </div>

                @php $balance = $total - (float)$paidAmount; @endphp

                @if($balance < 0)
                    <div class="bg-green-50 dark:bg-green-900/20 p-3 rounded-lg border border-green-200 dark:border-green-800 text-center">
                        <span class="text-green-700 dark:text-green-400 font-bold text-sm">Change:</span>
                        <div class="text-2xl font-black text-green-600 dark:text-green-400">₦{{ number_format(abs($balance)) }}</div>
                    </div>
                @elseif($balance > 0)
                    <div class="bg-red-50 dark:bg-red-900/20 p-3 rounded-lg border border-red-200 dark:border-red-800 text-center animate-pulse">
                        <span class="text-red-700 dark:text-red-400 font-bold text-sm">⚠️ Remaining Debt:</span>
                        <div class="text-2xl font-black text-red-600 dark:text-red-400">₦{{ number_format($balance) }}</div>
                    </div>

                    <div>
                        <label class="block text-sm font-bold text-red-600 mb-1">Select Guest for Debt *</label>
                        <div class="flex gap-2">
                            <select wire:model="selectedGuestId" class="w-full p-2 border border-red-300 rounded-lg dark:bg-gray-800 dark:border-red-900">
                                <option value="">-- Select Guest --</option>
                                @foreach(\App\Models\Guest::all() as $guest) 
                                    <option value="{{ $guest->id }}">{{ $guest->name }}</option>
                                @endforeach
                            </select>
                            <button wire:click="$set('showGuestModal', true)" class="bg-blue-600 hover:bg-blue-700 text-white px-3 rounded-lg text-lg font-bold flex items-center justify-center">
                                +
                            </button>
                        </div>
                        @error('selectedGuestId') <span class="text-xs text-red-600 font-bold">{{ $message }}</span> @enderror
                    </div>
                @endif

                <div>
                    <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Payment Method</label>
                    <div class="grid grid-cols-3 gap-2">
                        @foreach(['cash'=>'💵 Cash','pos'=>'💳 POS','transfer'=>'🏦 Transfer'] as $key => $label)
                        <button wire:click="$set('paymentMethod', '{{ $key }}')" class="p-2 border rounded-lg font-bold text-sm transition-colors {{ $paymentMethod === $key ? 'bg-blue-600 text-white border-blue-600' : 'hover:bg-gray-50 dark:hover:bg-gray-700 border-gray-300 dark:border-gray-600' }}">{{ $label }}</button>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="p-4 border-t border-gray-200 dark:border-gray-700 grid grid-cols-2 gap-3">
                <button wire:click="$set('showPaymentModal', false)" class="px-4 py-2 font-bold text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">Cancel</button>
                <button wire:click="processPayment" class="px-4 py-2 font-bold text-white bg-green-600 rounded-lg hover:bg-green-700 shadow-lg shadow-green-600/30 flex items-center justify-center gap-2"><span>Confirm</span></button>
            </div>
        </div>
    </div>
    @endif

    @if($showGuestModal)
    <div class="fixed inset-0 bg-black/60 z-[60] flex items-center justify-center p-4 backdrop-blur-sm">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden border border-gray-200 dark:border-gray-700">
            <div class="bg-gray-50 dark:bg-gray-800 p-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">👤 Add New Guest</h3>
                <button wire:click="$set('showGuestModal', false)" class="text-gray-400 hover:text-red-500"><span class="text-2xl">&times;</span></button>
            </div>
            
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-1">Full Name *</label>
                    <input type="text" wire:model="newGuestName" class="w-full p-2 border border-gray-300 rounded-lg dark:bg-gray-800 dark:border-gray-600" placeholder="e.g. Mr. John Doe">
                    @error('newGuestName') <span class="text-xs text-red-600 font-bold">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-1">Phone Number</label>
                    <input type="text" wire:model="newGuestPhone" class="w-full p-2 border border-gray-300 rounded-lg dark:bg-gray-800 dark:border-gray-600" placeholder="e.g. 08012345678">
                </div>
            </div>

            <div class="p-4 border-t border-gray-200 dark:border-gray-700 grid grid-cols-2 gap-3">
                <button wire:click="$set('showGuestModal', false)" class="px-4 py-2 font-bold text-gray-700 bg-gray-100 rounded-lg">Cancel</button>
                <button wire:click="saveNewGuest" class="px-4 py-2 font-bold text-white bg-blue-600 rounded-lg hover:bg-blue-700">Save Guest</button>
            </div>
        </div>
    </div>
    @endif
</div>