<?php

use Livewire\Component;
use App\Models\Table;
use App\Models\Order;
use App\Services\PinAuthService;
use App\Exceptions\PinLockedException;
use Illuminate\Support\Facades\Auth;
use Filament\Notifications\Notification;

new class extends Component {
    public ?int $selectedTableId = null;
    public string $selectedTableName = '';
    public ?string $errorMessage = null;
    public ?int $lockedUntilTimestamp = null;

    public function getTablesProperty()
    {
        return Table::with('latestActiveOrder.user:id,name')->orderBy('name')->get();
    }

    /**
     * Lets a waiter hand a customer their (unpaid) bill straight from the
     * table grid — no PIN needed, since this only reads and prints, it
     * never touches the order. Access to /kiosk at all already requires a
     * registered device cookie (EnsureValidKioskDevice), which is the
     * actual security boundary here.
     */
    public function printTableBill(int $tableId): void
    {
        $table = Table::find($tableId);

        if (!$table) {
            return;
        }

        $orders = Order::where('table_id', $tableId)
            ->whereNotIn('status', ['paid', 'cancelled'])
            ->with(['items', 'user'])
            ->get();

        if ($orders->isEmpty()) {
            Notification::make()->title('No items to print')->warning()->send();
            return;
        }

        $items = $orders->flatMap->items->map(fn ($item) => [
            'name' => $item->product_name,
            'price' => (float) $item->unit_price,
            'quantity' => $item->quantity,
        ])->values()->all();

        $total = collect($items)->sum(fn ($i) => $i['price'] * $i['quantity']);

        $this->dispatch('print-bill', [
            'tableName' => $table->name,
            'items' => $items,
            'total' => $total,
            'date' => now()->format('M j, Y g:i A'),
            'cashier' => $orders->first()->user?->name ?? 'Kiosk',
        ]);
    }

    public function selectTable(int $tableId, string $tableName): void
    {
        $this->selectedTableId = $tableId;
        $this->selectedTableName = $tableName;
        $this->errorMessage = null;
        $this->lockedUntilTimestamp = null;
    }

    public function closePinPad(): void
    {
        $this->selectedTableId = null;
        $this->errorMessage = null;
    }

    /**
     * Scoped to whichever device actually made the attempt — a shared
     * kiosk terminal, or a specific trusted personal phone — never a
     * single global bucket, which would let one person's failures lock out
     * everyone else.
     */
    protected function throttleKey(): string
    {
        $kioskDeviceId = session('kiosk_device_id');
        if ($kioskDeviceId) {
            return "kiosk:{$kioskDeviceId}";
        }

        $trustedUserId = session('trusted_device_user_id');
        if ($trustedUserId) {
            return "trusted:{$trustedUserId}";
        }

        return 'unscoped:' . request()->ip();
    }

    /**
     * The PIN itself is a plain method argument, not a synced public
     * property — digit taps are handled entirely client-side in Alpine
     * (see the template) and this is called exactly once, with the
     * complete 4-digit PIN, when the 4th digit lands. Previously every
     * single digit tap round-tripped to the server via pressDigit(),
     * making the pad feel sluggish on a real network — now there's
     * exactly one server call per login attempt, matching the same
     * client-first pattern used for the POS cart.
     */
    public function submitPin(string $pin): void
    {
        $service = new PinAuthService();

        try {
            $user = $service->attempt($pin, $this->throttleKey());
        } catch (PinLockedException $e) {
            $this->lockedUntilTimestamp = $e->lockedUntilTimestamp;
            $this->errorMessage = $e->getMessage();
            return;
        }

        if (!$user) {
            $this->errorMessage = 'Incorrect PIN.';
            return;
        }

        // On a personal phone, the device is already bound to one specific
        // person — even a correct PIN belonging to someone ELSE must not
        // work here, or a lost/stolen phone would grant access as whoever's
        // PIN happens to be guessed, not just its actual owner.
        $trustedUserId = session('trusted_device_user_id');
        if ($trustedUserId && (int) $trustedUserId !== $user->id) {
            $this->errorMessage = 'This PIN does not belong to this device\'s owner.';
            return;
        }

        Auth::guard('staff_pin')->login($user);

        // navigate: true swaps in the order screen over AJAX instead of a
        // full browser reload — the CSS/JS already loaded on this page
        // stays put, so the transition after a correct PIN feels close to
        // instant instead of a fresh page load. Safe now that pos.blade.php's
        // own boot() re-asserts Auth::shouldUse('staff_pin') on every
        // request rather than relying on this redirect's middleware pass.
        $routeName = session('kiosk_device_id') ? 'kiosk.order' : 'staff.order';
        $this->redirect(route($routeName, ['table' => $this->selectedTableId]), navigate: true);
    }
}; ?>

<div class="min-h-screen bg-gray-900 p-6" x-data="{}" @print-bill.window="printPOSBill($event.detail[0] ?? $event.detail)">
    <h1 class="text-2xl font-bold text-white mb-6">Select a Table</h1>

    <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 gap-4">
        @foreach ($this->tables as $table)
            <div class="relative" x-data="{ showMenu: false }">
                <button wire:click="selectTable({{ $table->id }}, '{{ $table->name }}')"
                    class="w-full aspect-square rounded-xl flex flex-col items-center justify-center font-bold text-lg
                        {{ $table->status === 'available' ? 'bg-green-600 text-white' : 'bg-amber-600 text-white' }}">
                    {{ $table->name }}
                    <span class="text-xs font-normal mt-1">{{ ucfirst($table->status) }}</span>
                    @if ($table->latestActiveOrder?->user)
                        <span class="text-[10px] font-normal opacity-80 mt-0.5">{{ $table->latestActiveOrder->user->name }}</span>
                    @endif
                </button>

                {{-- Menu button + Print Bill: reachable without ever logging
                     in via PIN, since printing only reads the order — the
                     registered-device cookie required to reach /kiosk at
                     all is the actual security boundary here. --}}
                <button type="button" @click.stop="showMenu = !showMenu"
                    class="absolute top-1 right-1 w-8 h-8 flex items-center justify-center rounded-full bg-black/30 hover:bg-black/50 text-white text-lg leading-none touch-manipulation">
                    &#8942;
                </button>

                <div x-show="showMenu" x-cloak @click.outside="showMenu = false"
                    class="absolute top-10 right-1 z-20 bg-white rounded-lg shadow-lg border border-gray-200 overflow-hidden w-36">
                    <button type="button" @click="showMenu = false"
                        wire:click="printTableBill({{ $table->id }})"
                        class="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-100 touch-manipulation">
                        &#128424;&#65039; Print Bill
                    </button>
                </div>
            </div>
        @endforeach
    </div>

    @if ($selectedTableId)
        <div class="fixed inset-0 bg-black/70 flex items-center justify-center z-50" wire:click.self="closePinPad"
            x-data="{ submitting: false }"
            @pin-entered="
                submitting = true
                $wire.submitPin($event.detail).then(() => { submitting = false })
            ">
            <div class="bg-white rounded-2xl p-6 w-full max-w-xs relative overflow-hidden">
                <h2 class="text-lg font-bold text-gray-900 mb-1">{{ $selectedTableName }}</h2>
                <p class="text-sm text-gray-500 mb-4">Enter your 4-digit PIN</p>

                <x-pin-keypad :error-message="$errorMessage">
                    <x-slot:extraButton>
                        <button wire:click="closePinPad"
                            class="py-4 bg-gray-200 rounded-lg text-sm font-bold text-gray-600 touch-manipulation active:scale-95 transition-transform">Cancel</button>
                    </x-slot:extraButton>
                </x-pin-keypad>

                {{-- Fast, unmistakable "it registered, we're working on it" feedback
                     the instant the 4th digit lands — covers the pad so there's no
                     dead-looking screen while the login request is in flight. --}}
                <div x-show="submitting" x-cloak x-transition.opacity.duration.100ms
                    class="absolute inset-0 bg-white/95 flex flex-col items-center justify-center gap-3">
                    <svg class="animate-spin h-10 w-10 text-amber-500" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    <p class="text-sm font-bold text-gray-600">Logging in…</p>
                </div>
            </div>
        </div>
    @endif
</div>
