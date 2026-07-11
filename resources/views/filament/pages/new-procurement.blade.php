<div class="min-h-screen p-6 bg-gradient-to-br from-gray-50 to-gray-100 dark:from-gray-900 dark:to-gray-950">
    <div class="max-w-5xl mx-auto mb-6">
        <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Record Procurement</h2>
        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
            Goods received into {{ $mainStore->name ?? 'Main Store' }} — crates/cartons convert to base units automatically.
        </p>
    </div>

    <div class="max-w-5xl mx-auto bg-white dark:bg-gray-800 rounded-2xl shadow-xl border border-gray-200 dark:border-gray-700 p-6 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <div>
                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Supplier (optional)</label>
                <input type="text" wire:model="supplierName" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Purchase date</label>
                <input type="date" wire:model="purchasedAt" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100">
            </div>
        </div>

        {{-- Entry area: frozen from Livewire re-renders once mounted (wire:ignore) so
             in-progress typing/selection survives every $wire.call() that follows —
             the counting/review flows earlier this session hit the same reset bug
             when x-data embeds server data directly without this. --}}
        <div wire:ignore
             x-data="{
                products: @js($products),
                ingredients: @js($ingredients),
                categories: @js($categories),
                type: 'product',
                selectedId: '',
                creatingNew: false,
                newItem: { name: '', category_id: '', base_unit: 'bottle', unit_name: 'kg', purchase_unit_name: '', units_per_purchase_unit: '' },
                enteredQty: 1,
                enteredUnit: 'base_unit',
                lineTotalCost: '',
                get currentList() { return this.type === 'product' ? this.products : this.ingredients; },
                get selected() { return this.selectedId ? this.currentList.find(i => i.id == this.selectedId) : null; },
                get unitsPerPurchaseUnit() {
                    if (this.creatingNew) return this.newItem.units_per_purchase_unit ? parseInt(this.newItem.units_per_purchase_unit) : null;
                    return (this.selected && this.selected.units_per_purchase_unit) || null;
                },
                get purchaseUnitName() {
                    if (this.creatingNew) return this.newItem.purchase_unit_name;
                    return (this.selected && this.selected.purchase_unit_name) || null;
                },
                get baseUnitLabel() {
                    if (this.type === 'product') return this.creatingNew ? (this.newItem.base_unit || 'bottle') : ((this.selected && this.selected.base_unit) || 'bottle');
                    return this.creatingNew ? (this.newItem.unit_name || 'kg') : ((this.selected && this.selected.unit_name) || 'kg');
                },
                get baseQty() {
                    const qty = parseFloat(this.enteredQty) || 0;
                    if (this.enteredUnit === 'purchase_unit' && this.unitsPerPurchaseUnit) {
                        return Math.round(qty * this.unitsPerPurchaseUnit * 100) / 100;
                    }
                    return Math.round(qty * 100) / 100;
                },
                get unitCost() {
                    const total = parseFloat(this.lineTotalCost) || 0;
                    return this.baseQty > 0 ? (total / this.baseQty).toFixed(4) : '0.0000';
                },
                get fuzzyMatches() {
                    if (!this.creatingNew || !this.newItem.name || this.newItem.name.length < 2) return [];
                    const term = this.newItem.name.toLowerCase();
                    return this.currentList.filter(i => i.name.toLowerCase().includes(term)).slice(0, 5);
                },
                resetEntry() {
                    this.selectedId = ''; this.creatingNew = false;
                    this.newItem = { name: '', category_id: '', base_unit: 'bottle', unit_name: 'kg', purchase_unit_name: '', units_per_purchase_unit: '' };
                    this.enteredQty = 1; this.enteredUnit = 'base_unit'; this.lineTotalCost = '';
                },
                pickFuzzy(item) { this.creatingNew = false; this.selectedId = item.id; },
                addLine() {
                    if (!this.creatingNew && !this.selectedId) { alert('Select an item or create a new one.'); return; }
                    if (this.creatingNew && !this.newItem.name) { alert('Enter a name for the new item.'); return; }
                    if (this.creatingNew && this.type === 'product' && !this.newItem.category_id) { alert('Choose a category for the new product.'); return; }
                    if (!this.lineTotalCost || parseFloat(this.lineTotalCost) <= 0) { alert('Enter the line total cost.'); return; }

                    const payload = {
                        entered_qty: parseFloat(this.enteredQty) || 0,
                        entered_unit: this.enteredUnit,
                        line_total_cost: parseFloat(this.lineTotalCost) || 0,
                        display_name: this.creatingNew ? (this.newItem.name + ' (new)') : this.selected.name,
                    };

                    if (this.type === 'product') {
                        if (this.creatingNew) {
                            payload.new_product = {
                                name: this.newItem.name,
                                category_id: this.newItem.category_id,
                                base_unit: this.newItem.base_unit || 'bottle',
                                purchase_unit_name: this.newItem.purchase_unit_name || null,
                                units_per_purchase_unit: this.newItem.units_per_purchase_unit ? parseInt(this.newItem.units_per_purchase_unit) : null,
                            };
                        } else {
                            payload.product_id = parseInt(this.selectedId);
                        }
                        $wire.call('addProductLine', payload);
                    } else {
                        if (this.creatingNew) {
                            payload.new_ingredient = {
                                name: this.newItem.name,
                                unit_name: this.newItem.unit_name || 'kg',
                                purchase_unit_name: this.newItem.purchase_unit_name || null,
                                units_per_purchase_unit: this.newItem.units_per_purchase_unit ? parseInt(this.newItem.units_per_purchase_unit) : null,
                            };
                        } else {
                            payload.ingredient_id = parseInt(this.selectedId);
                        }
                        $wire.call('addIngredientLine', payload);
                    }

                    this.resetEntry();
                },
             }"
             class="border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-xl p-4">

            <div class="flex gap-2 mb-4">
                <button type="button" @click="type = 'product'; resetEntry()"
                        :class="type === 'product' ? 'bg-blue-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300'"
                        class="px-4 py-2 rounded-lg text-sm font-semibold">Product</button>
                <button type="button" @click="type = 'ingredient'; resetEntry()"
                        :class="type === 'ingredient' ? 'bg-blue-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300'"
                        class="px-4 py-2 rounded-lg text-sm font-semibold">Ingredient</button>
            </div>

            <template x-if="!creatingNew">
                <div class="mb-4">
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Item</label>
                    <div class="flex gap-2">
                        <select x-model="selectedId" class="flex-1 px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100">
                            <option value="">Select…</option>
                            <template x-for="item in currentList" :key="item.id">
                                <option :value="item.id" x-text="item.name"></option>
                            </template>
                        </select>
                        <button type="button" @click="creatingNew = true; selectedId = ''"
                                class="px-3 py-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 text-sm font-semibold whitespace-nowrap">+ New</button>
                    </div>
                </div>
            </template>

            <template x-if="creatingNew">
                <div class="mb-4 space-y-3 bg-blue-50 dark:bg-gray-900/50 rounded-lg p-3 border border-blue-200 dark:border-gray-700">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-semibold text-blue-800 dark:text-blue-300" x-text="'New ' + type"></span>
                        <button type="button" @click="creatingNew = false" class="text-xs text-gray-500 hover:underline">Cancel</button>
                    </div>
                    <input type="text" x-model="newItem.name" placeholder="Name" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100">

                    <template x-if="fuzzyMatches.length > 0">
                        <div class="text-xs text-amber-700 dark:text-amber-400 space-y-1">
                            <p>Did you mean an existing item?</p>
                            <template x-for="match in fuzzyMatches" :key="match.id">
                                <button type="button" @click="pickFuzzy(match)" class="block underline" x-text="match.name"></button>
                            </template>
                        </div>
                    </template>

                    <template x-if="type === 'product'">
                        <select x-model="newItem.category_id" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100">
                            <option value="">Select category…</option>
                            <template x-for="cat in categories" :key="cat.id">
                                <option :value="cat.id" x-text="cat.name"></option>
                            </template>
                        </select>
                    </template>

                    <div class="grid grid-cols-2 gap-2">
                        <template x-if="type === 'product'">
                            <input type="text" x-model="newItem.base_unit" placeholder="Base unit (e.g. bottle)" class="px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100">
                        </template>
                        <template x-if="type === 'ingredient'">
                            <input type="text" x-model="newItem.unit_name" placeholder="Base unit (e.g. kg)" class="px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100">
                        </template>
                        <input type="text" x-model="newItem.purchase_unit_name" placeholder="Purchase unit (e.g. crate)" class="px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100">
                    </div>
                    <input type="number" min="2" x-model="newItem.units_per_purchase_unit" placeholder="Units per purchase unit (e.g. 12)" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100">
                </div>
            </template>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-400 mb-1">Quantity</label>
                    <input type="number" min="0.01" step="0.01" x-model="enteredQty" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-400 mb-1">Unit</label>
                    <select x-model="enteredUnit" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100">
                        <option value="base_unit" x-text="baseUnitLabel"></option>
                        <template x-if="unitsPerPurchaseUnit">
                            <option value="purchase_unit" x-text="purchaseUnitName + ' (' + unitsPerPurchaseUnit + ' ' + baseUnitLabel + ')'"></option>
                        </template>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-400 mb-1">Line total cost (₦)</label>
                    <input type="number" min="0.01" step="0.01" x-model="lineTotalCost" class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100">
                </div>
            </div>

            <div class="text-sm text-gray-600 dark:text-gray-400 mb-3">
                = <span class="font-semibold" x-text="baseQty"></span> <span x-text="baseUnitLabel"></span>
                · <span class="font-semibold">₦<span x-text="unitCost"></span></span>/<span x-text="baseUnitLabel"></span>
            </div>

            <button type="button" @click="addLine()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold text-sm">Add line</button>
        </div>

        {{-- Review table: reactive to $productLines/$ingredientLines, so this part
             re-renders normally on every addLine()/remove call. --}}
        <div class="mt-6">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400 text-xs uppercase font-semibold">
                        <th class="py-2 px-2">Item</th>
                        <th class="py-2 px-2 text-center">Entered</th>
                        <th class="py-2 px-2 text-center">Cost</th>
                        <th class="py-2 px-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($productLines as $i => $line)
                        <tr class="border-t border-gray-100 dark:border-gray-700">
                            <td class="py-2 px-2 text-gray-900 dark:text-gray-100">{{ $line['display_name'] ?? '—' }}</td>
                            <td class="py-2 px-2 text-center text-gray-600 dark:text-gray-400">{{ $line['entered_qty'] }} {{ $line['entered_unit'] === 'purchase_unit' ? 'pack(s)' : 'base' }}</td>
                            <td class="py-2 px-2 text-center text-gray-600 dark:text-gray-400">₦{{ number_format((float) $line['line_total_cost'], 2) }}</td>
                            <td class="py-2 px-2 text-right">
                                <button type="button" wire:click="removeProductLine({{ $i }})" class="text-red-600 dark:text-red-400 text-xs font-semibold">Remove</button>
                            </td>
                        </tr>
                    @endforeach
                    @foreach ($ingredientLines as $i => $line)
                        <tr class="border-t border-gray-100 dark:border-gray-700">
                            <td class="py-2 px-2 text-gray-900 dark:text-gray-100">{{ $line['display_name'] ?? '—' }}</td>
                            <td class="py-2 px-2 text-center text-gray-600 dark:text-gray-400">{{ $line['entered_qty'] }} {{ $line['entered_unit'] === 'purchase_unit' ? 'pack(s)' : 'base' }}</td>
                            <td class="py-2 px-2 text-center text-gray-600 dark:text-gray-400">₦{{ number_format((float) $line['line_total_cost'], 2) }}</td>
                            <td class="py-2 px-2 text-right">
                                <button type="button" wire:click="removeIngredientLine({{ $i }})" class="text-red-600 dark:text-red-400 text-xs font-semibold">Remove</button>
                            </td>
                        </tr>
                    @endforeach
                    @if (empty($productLines) && empty($ingredientLines))
                        <tr><td colspan="4" class="py-6 text-center text-gray-500 dark:text-gray-400">No lines added yet.</td></tr>
                    @endif
                </tbody>
            </table>

            @php
                $total = collect($productLines)->sum('line_total_cost') + collect($ingredientLines)->sum('line_total_cost');
            @endphp
            <div class="flex items-center justify-between mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                <span class="font-semibold text-gray-900 dark:text-gray-100">Total: ₦{{ number_format($total, 2) }}</span>
                <button type="button" wire:click="save" wire:loading.attr="disabled"
                        class="px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white rounded-xl font-semibold shadow-lg disabled:opacity-50">
                    Save procurement
                </button>
            </div>
        </div>
    </div>

    {{-- Recent Procurements (deferred) --}}
    <div wire:init="load" class="max-w-5xl mx-auto bg-white dark:bg-gray-800 rounded-2xl shadow-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="p-6 bg-gradient-to-br from-gray-50 to-gray-100 dark:from-gray-900 dark:to-gray-800 border-b border-gray-200 dark:border-gray-700">
            <div class="font-bold text-gray-900 dark:text-gray-100">Recent Procurements</div>
        </div>
        <div class="p-6">
            @if (! $ready)
                @include('filament.widgets._deferred-placeholder')
            @else
                @if ($recentProcurements->count() > 0)
                    <div class="space-y-3">
                        @foreach ($recentProcurements as $procurement)
                            <div class="p-4 bg-gray-50 dark:bg-gray-900/50 rounded-xl border border-gray-200 dark:border-gray-700">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="font-semibold text-gray-900 dark:text-white">{{ $procurement->reference }}</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ $procurement->purchased_at->format('d M Y') }}
                                            @if ($procurement->supplier_name) · {{ $procurement->supplier_name }} @endif
                                            · {{ $procurement->items->count() + $procurement->ingredientItems->count() }} line(s)
                                            · by {{ $procurement->recordedBy->name ?? 'Unknown' }}
                                        </p>
                                    </div>
                                    <p class="font-semibold text-gray-900 dark:text-gray-100">₦{{ number_format((float) $procurement->total_cost, 2) }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    @if ($recentProcurements->hasPages())
                        <div class="mt-6 flex justify-center">{{ $recentProcurements->links() }}</div>
                    @endif
                @else
                    <div class="text-sm text-gray-600 dark:text-gray-400 text-center py-8">No procurements recorded yet.</div>
                @endif
            @endif
        </div>
    </div>
</div>
