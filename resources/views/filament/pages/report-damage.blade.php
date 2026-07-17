<x-filament-panels::page>
    <div class="max-w-xl mx-auto space-y-6"
        x-data="{
            itemType: @entangle('itemType'),
            enteredUnit: @entangle('enteredUnit'),
            enteredQty: @entangle('enteredQty'),
            products: @js($products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'unit' => $p->purchase_unit_name, 'perUnit' => $p->units_per_purchase_unit])),
            ingredients: @js($ingredients->map(fn ($i) => ['id' => $i->id, 'name' => $i->name, 'unit' => $i->purchase_unit_name, 'perUnit' => $i->units_per_purchase_unit])),
            selectedId: null,
            get selectedItem() {
                const list = this.itemType === 'product' ? this.products : this.ingredients;
                return list.find(i => i.id == this.selectedId) ?? null;
            },
            get basePreview() {
                const qty = parseFloat(this.enteredQty) || 0;
                if (this.enteredUnit === 'purchase_unit' && this.selectedItem?.perUnit) {
                    return (qty * this.selectedItem.perUnit).toLocaleString();
                }
                return qty.toLocaleString();
            },
        }">
        <div class="bg-gradient-to-r from-amber-50 to-orange-50 dark:from-gray-800 dark:to-gray-900 rounded-lg p-6 border border-amber-200 dark:border-gray-700">
            <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Report Damage</h2>
            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                A breakage or write-off, sent to a manager for approval. This never removes stock immediately —
                nothing changes until it's approved.
            </p>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 space-y-5">
            <div class="grid grid-cols-2 gap-2">
                <button type="button" @click="itemType = 'product'; selectedId = null; $wire.set('productId', null); $wire.set('ingredientId', null)"
                    :class="itemType === 'product' ? 'bg-amber-500 text-white' : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300'"
                    class="py-3 rounded-xl font-bold text-sm touch-manipulation min-h-[48px]">Product</button>
                <button type="button" @click="itemType = 'ingredient'; selectedId = null; $wire.set('productId', null); $wire.set('ingredientId', null)"
                    :class="itemType === 'ingredient' ? 'bg-amber-500 text-white' : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300'"
                    class="py-3 rounded-xl font-bold text-sm touch-manipulation min-h-[48px]">Ingredient</button>
            </div>

            <div>
                <label class="block text-xs font-bold uppercase text-gray-500 dark:text-gray-400 mb-1">Item</label>
                <select x-model="selectedId"
                    @change="itemType === 'product' ? $wire.set('productId', selectedId) : $wire.set('ingredientId', selectedId)"
                    class="w-full h-12 rounded-xl border-2 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3">
                    <option value="">Choose an item…</option>
                    <template x-for="item in (itemType === 'product' ? products : ingredients)" :key="item.id">
                        <option :value="item.id" x-text="item.name"></option>
                    </template>
                </select>
            </div>

            <template x-if="selectedItem?.perUnit">
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" @click="enteredUnit = 'base_unit'"
                        :class="enteredUnit === 'base_unit' ? 'bg-amber-500 text-white' : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300'"
                        class="py-2 rounded-lg text-xs font-bold touch-manipulation min-h-[48px]">Base unit</button>
                    <button type="button" @click="enteredUnit = 'purchase_unit'"
                        :class="enteredUnit === 'purchase_unit' ? 'bg-amber-500 text-white' : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300'"
                        class="py-2 rounded-lg text-xs font-bold touch-manipulation min-h-[48px]" x-text="selectedItem.unit ?? 'Purchase unit'"></button>
                </div>
            </template>

            <div>
                <label class="block text-xs font-bold uppercase text-gray-500 dark:text-gray-400 mb-1">Quantity</label>
                <input type="number" step="0.01" min="0" x-model="enteredQty"
                    class="w-full h-12 rounded-xl border-2 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 text-lg font-mono">
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1" x-show="enteredUnit === 'purchase_unit' && selectedItem?.perUnit">
                    = <span x-text="basePreview"></span> base unit(s)
                </p>
            </div>

            <div>
                <label class="block text-xs font-bold uppercase text-gray-500 dark:text-gray-400 mb-1">Note</label>
                <textarea wire:model="note" rows="3" required
                    class="w-full rounded-xl border-2 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white p-3"
                    placeholder="What happened?"></textarea>
            </div>

            <button type="button" wire:click="submit" wire:loading.attr="disabled" wire:target="submit"
                class="w-full min-h-[48px] py-4 rounded-xl bg-amber-600 hover:bg-amber-700 text-white text-lg font-bold touch-manipulation disabled:opacity-50">
                Submit for approval
            </button>
        </div>
    </div>
</x-filament-panels::page>
