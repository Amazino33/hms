<?php

use Livewire\Component;
use App\Models\Product;
use App\Services\DamageReportService;
use App\Services\InventoryService;
use Filament\Notifications\Livewire\Notifications;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\VerticalAlignment;

/**
 * Bartender kiosk entry point for a damage/write-off report. Product-only
 * (the bar only stocks products, unlike the storekeeper's Filament-side
 * entry which also covers ingredients) — no packaging-unit toggle either,
 * since bar stock is already tracked bottle-by-bottle at this level; pack
 * conversion belongs to the storekeeper/procurement layer, not here.
 *
 * Blind-count protection: products() deliberately selects only id/name —
 * no stock figures anywhere on this screen, matching the count screens'
 * own convention.
 */
new class extends Component {
    public string $search = '';

    public ?int $productId = null;

    public ?string $productName = null;

    public ?float $quantity = null;

    public string $note = '';

    public bool $submitting = false;

    public function mount(): void
    {
        Notifications::alignment(Alignment::Center);
        Notifications::verticalAlignment(VerticalAlignment::Start);
    }

    public function searchResults(): array
    {
        if (trim($this->search) === '') {
            return [];
        }

        return Product::query()
            ->where('is_active', true)
            ->where('name', 'like', '%' . $this->search . '%')
            ->limit(20)
            ->get(['id', 'name'])
            ->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name])
            ->all();
    }

    public function selectProduct(int $id, string $name): void
    {
        $this->productId = $id;
        $this->productName = $name;
        $this->search = '';
    }

    public function clearSelection(): void
    {
        $this->productId = null;
        $this->productName = null;
    }

    /**
     * @return array{ok: bool, message?: string}
     */
    public function submit(): array
    {
        try {
            if (! $this->productId) {
                return ['ok' => false, 'message' => 'Choose a product first.'];
            }

            app(DamageReportService::class)->report(
                ['product_id' => $this->productId, 'quantity' => (float) $this->quantity, 'note' => $this->note],
                InventoryService::getBarWarehouseId(),
                auth()->id(),
            );

            $this->reset(['productId', 'productName', 'quantity', 'note', 'search']);

            return ['ok' => true];
        } catch (\Throwable $e) {
            report($e);

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function backToOrders(): void
    {
        $routeName = session('kiosk_device_id') ? 'kiosk.home' : 'staff.home';
        $this->redirect(route($routeName), navigate: true);
    }
}; ?>

<div class="min-h-screen bg-gray-900 p-4 flex flex-col" x-data="{
        toast: null,
        showToast(msg, ok) {
            this.toast = { msg, ok };
            setTimeout(() => { this.toast = null }, 3000);
        },
    }">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-white">Report Damage</h1>
        <button wire:click="backToOrders" class="px-4 py-3 min-h-[48px] rounded-xl bg-gray-700 text-white font-bold touch-manipulation">Back</button>
    </div>

    <div class="bg-white rounded-2xl p-5 max-w-md w-full mx-auto space-y-4">
        @if($productId)
            <div class="flex items-center justify-between bg-amber-50 rounded-xl p-4">
                <span class="font-bold text-gray-900">{{ $productName }}</span>
                <button wire:click="clearSelection" class="text-sm font-bold text-gray-500 min-h-[48px] px-3">Change</button>
            </div>
        @else
            <div>
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search product…"
                    class="w-full h-14 px-4 text-lg rounded-xl border-2 border-gray-200">
                @if($search)
                    <div class="mt-2 max-h-64 overflow-y-auto space-y-1">
                        @foreach($this->searchResults() as $result)
                            <button wire:click="selectProduct({{ $result['id'] }}, '{{ addslashes($result['name']) }}')"
                                class="w-full text-left px-4 py-3 min-h-[48px] rounded-lg bg-gray-100 hover:bg-amber-100 font-medium text-gray-900 touch-manipulation">
                                {{ $result['name'] }}
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        <div x-data="{ quantity: @entangle('quantity') }">
            <label class="block text-xs font-bold uppercase text-gray-500 mb-1">Quantity</label>
            <x-mobile.stepper model="quantity" :min="0.01" :step="1" />
        </div>

        <div>
            <label class="block text-xs font-bold uppercase text-gray-500 mb-1">Note</label>
            <textarea wire:model="note" rows="3" placeholder="What happened?"
                class="w-full rounded-xl border-2 border-gray-200 p-3"></textarea>
        </div>

        <button type="button"
            x-on:click="
                $wire.submit().then((result) => {
                    if (result.ok) { showToast('Reported — awaiting manager approval', true) }
                    else { showToast(result.message ?? 'Could not report damage', false) }
                }).catch(() => { showToast('Could not report damage — please try again', false) })
            "
            class="w-full min-h-[64px] py-4 rounded-xl bg-amber-600 text-white text-lg font-bold touch-manipulation">
            Submit for approval
        </button>
    </div>

    <div x-show="toast" x-cloak x-transition
        :class="toast?.ok ? 'bg-emerald-600' : 'bg-red-600'"
        class="fixed top-4 left-1/2 -translate-x-1/2 z-50 text-white font-bold px-6 py-4 rounded-xl shadow-2xl min-h-[48px] flex items-center"
        x-text="toast?.msg"></div>
</div>
