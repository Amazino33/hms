<div class="min-h-screen p-6 bg-gradient-to-br from-gray-50 to-gray-100 dark:from-gray-900 dark:to-gray-950">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <!-- Toast notifications -->
    <div id="toast" class="fixed top-6 right-6 z-50 hidden">
        <div class="px-6 py-4 rounded-xl shadow-2xl backdrop-blur-sm transform transition-all duration-300" id="toast-content"></div>
    </div>

    <!-- Header -->
    <div class="max-w-7xl mx-auto mb-8">
        <div class="flex items-center gap-3 mb-2">
            <div>
                <h2 class="text-3xl font-bold bg-gradient-to-r from-gray-900 to-gray-700 dark:from-white dark:to-gray-300 bg-clip-text text-transparent">Create Stock Transfer</h2>
                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Transfer inventory between warehouse locations</p>
            </div>
        </div>
    </div>

    <div class="max-w-7xl mx-auto grid grid-cols-1 lg:grid-cols-1 gap-6">
        <!-- Main Form -->
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="p-6">
                <div id="form-errors" class="mb-4 hidden rounded-xl p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300"></div>
                
                <form id="transfer-form" method="POST" action="/stock-transfers">
                    <!-- Warehouse Selection -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div class="group">
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                                <span class="flex items-center gap-2">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                                    </svg>
                                    From Warehouse
                                </span>
                            </label>
                            <select name="from_warehouse_id" class="w-full px-4 py-3 rounded-xl border-2 border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none">
                                <option value="">Select From Warehouse</option>
                                @foreach($warehouses as $warehouse)
                                    <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="group">
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                                <span class="flex items-center gap-2">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16l-4-4m0 0l4-4m-4 4h18"></path>
                                    </svg>
                                    To Warehouse
                                </span>
                            </label>
                            <select name="to_warehouse_id" class="w-full px-4 py-3 rounded-xl border-2 border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200 outline-none">
                                <option value="">Select To Warehouse</option>
                                @foreach($warehouses as $warehouse)
                                    <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <!-- Items Section -->
                    <div class="mb-6">
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Transfer Items</label>
                        <div id="items-list" class="space-y-3"></div>
                        <div class="mt-4 md:flex md:justify-start flex justify-center">
                            <button type="button" onclick="addItemRow()" class="flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-gray-100 to-gray-200 dark:from-gray-700 dark:to-gray-600 hover:from-gray-200 hover:to-gray-300 dark:hover:from-gray-600 dark:hover:to-gray-500 text-gray-800 dark:text-gray-100 rounded-xl font-medium transition-all shadow-sm hover:shadow">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                </svg>
                                Add Item
                            </button>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="flex items-center md:justify-end justify-center gap-3 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <button type="submit" class="px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white rounded-xl font-semibold shadow-lg hover:shadow-xl transform hover:scale-105 transition-all duration-200">
                            Create Transfer
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Preview Panel -->
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl border border-gray-200 dark:border-gray-700 overflow-hidden h-fit lg:sticky lg:top-6">
            <div class="p-6 bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-gray-900 dark:to-gray-800 border-b border-gray-200 dark:border-gray-700">
                <div class="flex flex-col gap-2">
                    <div class="font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                        <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                        </svg>
                        Transfer Preview
                    </div>
                    <div class="px-3 py-1 bg-blue-600 text-white text-xs font-bold rounded-full self-start" id="preview-count">0 items</div>
                </div>
            </div>

            <div class="p-6">
                <!-- Mobile-friendly table -->
                <div class="overflow-x-auto lg:overflow-x-visible hidden lg:block">
                    <table class="w-full text-sm">
                        <thead class="hidden lg:table-header-group">
                            <tr class="text-left text-gray-500 dark:text-gray-400 text-xs uppercase font-semibold">
                                <th class="py-3 px-2">Item</th>
                                <th class="py-3 px-2 text-center">Qty</th>
                                <th class="py-3 px-2 text-center">= Base</th>
                                <th class="py-3 px-2 text-center">Avail</th>
                            </tr>
                        </thead>
                        <tbody id="preview-body">
                            <!-- Dynamic content will be inserted here -->
                        </tbody>
                    </table>
                </div>

                <!-- Mobile card layout for preview items -->
                <div class="lg:hidden space-y-3 mt-4" id="mobile-preview-body">
                    <!-- Dynamic content will be inserted here -->
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Transfers (deferred) -->
    <div wire:init="load" class="max-w-7xl mx-auto mt-6 bg-white dark:bg-gray-800 rounded-2xl shadow-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="p-6 bg-gradient-to-br from-gray-50 to-gray-100 dark:from-gray-900 dark:to-gray-800 border-b border-gray-200 dark:border-gray-700">
            <div class="font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                <svg class="w-4 h-4 text-gray-600 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                Recent Transfers
            </div>
        </div>
        <div class="p-6">
            @if (! $ready)
                @include('filament.widgets._deferred-placeholder')
            @else
                @if($recentTransfers->count() > 0)
                    <div class="space-y-4">
                        @foreach($recentTransfers as $transfer)
                            <div class="p-4 bg-gray-50 dark:bg-gray-900/50 rounded-xl border border-gray-200 dark:border-gray-700">
                                <!-- Mobile: Stack vertically, Desktop: Side by side -->
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                                    <!-- Left side: Icon and main info -->
                                    <div class="flex flex-col gap-3 flex-1">
                                        <!-- Row 1: Icon and Transfer Number -->
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg flex items-center justify-center flex-shrink-0">
                                                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                                                </svg>
                                            </div>
                                            <div>
                                                <p class="font-semibold text-gray-900 dark:text-white text-lg">{{ $transfer->transfer_number }}</p>
                                            </div>
                                        </div>
                                        
                                        <!-- Row 2: From → To Warehouses -->
                                        <div>
                                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                                <span class="font-medium">From:</span> {{ $transfer->fromWarehouse->name ?? 'Unknown' }}
                                            </p>
                                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                                <span class="font-medium">To:</span> {{ $transfer->toWarehouse->name ?? 'Unknown' }}
                                            </p>
                                        </div>
                                        
                                        <!-- Row 3: Line items — what was actually transferred, sent
                                             vs. received, and any discrepancy — not just a count, so
                                             the storekeeper can see exactly what happened to each
                                             transfer without opening anything else. -->
                                        <div class="space-y-1.5">
                                            @foreach($transfer->items as $line)
                                                @php($discrepancy = $line->discrepancy)
                                                <div class="flex flex-wrap items-center gap-2 text-sm">
                                                    <span class="font-medium text-gray-800 dark:text-gray-200">{{ $line->product->name ?? 'Unknown product' }}</span>
                                                    <span class="text-gray-500 dark:text-gray-400">sent {{ rtrim(rtrim(number_format($line->quantity, 2), '0'), '.') }}</span>
                                                    @if($line->received_quantity !== null)
                                                        <span class="text-gray-500 dark:text-gray-400">· received {{ rtrim(rtrim(number_format($line->received_quantity, 2), '0'), '.') }}</span>
                                                    @endif
                                                    <span class="px-2 py-0.5 text-xs font-semibold rounded-full
                                                        @if($line->outcome === 'received_full') bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300
                                                        @elseif($line->outcome === 'received_short') bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300
                                                        @elseif($line->outcome === 'rejected') bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300
                                                        @else bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300
                                                        @endif">
                                                        {{ str_replace('_', ' ', ucfirst($line->outcome ?? 'pending')) }}
                                                    </span>
                                                    @if($discrepancy && $discrepancy->isOpen())
                                                        <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-red-600 text-white">
                                                            ⚠ {{ rtrim(rtrim(number_format($discrepancy->missing_base_qty, 2), '0'), '.') }} unresolved
                                                        </span>
                                                    @endif
                                                </div>
                                            @endforeach
                                            @foreach($transfer->ingredientItems as $line)
                                                @php($discrepancy = $line->discrepancy)
                                                <div class="flex flex-wrap items-center gap-2 text-sm">
                                                    <span class="font-medium text-gray-800 dark:text-gray-200">{{ $line->ingredient->name ?? 'Unknown ingredient' }}</span>
                                                    <span class="text-gray-500 dark:text-gray-400">sent {{ rtrim(rtrim(number_format($line->quantity, 2), '0'), '.') }}</span>
                                                    @if($line->received_quantity !== null)
                                                        <span class="text-gray-500 dark:text-gray-400">· received {{ rtrim(rtrim(number_format($line->received_quantity, 2), '0'), '.') }}</span>
                                                    @endif
                                                    <span class="px-2 py-0.5 text-xs font-semibold rounded-full
                                                        @if($line->outcome === 'received_full') bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300
                                                        @elseif($line->outcome === 'received_short') bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300
                                                        @elseif($line->outcome === 'rejected') bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300
                                                        @else bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300
                                                        @endif">
                                                        {{ str_replace('_', ' ', ucfirst($line->outcome ?? 'pending')) }}
                                                    </span>
                                                    @if($discrepancy && $discrepancy->isOpen())
                                                        <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-red-600 text-white">
                                                            ⚠ {{ rtrim(rtrim(number_format($discrepancy->missing_base_qty, 2), '0'), '.') }} unresolved
                                                        </span>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>

                                    <!-- Right side: Status and timestamp -->
                                    <div class="flex flex-col items-start md:items-end gap-2">
                                        <span class="px-3 py-1 text-xs font-semibold rounded-full
                                            @if($transfer->status === 'pending') bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300
                                            @elseif($transfer->status === 'sent') bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300
                                            @elseif($transfer->status === 'partially_received') bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-300
                                            @elseif($transfer->status === 'received') bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300
                                            @else bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-300
                                            @endif">
                                            {{ str_replace('_', ' ', ucfirst($transfer->status ?? 'unknown')) }}
                                        </span>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $transfer->created_at->diffForHumans() }}</p>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    
                    @if($recentTransfers->hasPages())
                        <div class="mt-6 flex justify-center">
                            {{ $recentTransfers->links() }}
                        </div>
                    @endif
                @else
                    <div class="text-sm text-gray-600 dark:text-gray-400 text-center py-8">No transfers yet. Create your first transfer above.</div>
                @endif
            @endif
        </div>
    </div>

    <!-- stop <script> tag from giving only one root error for livewire -->
    @push('scripts')
    <script>
        let idx = 0;
        const allProducts = @json($products);
        const allIngredients = @json($ingredients);
        const warehouses = @json($warehouses);
        const availabilityCache = {}; // keyed by `${type}:${id}` -> quantity

        function getFilteredProducts(toWarehouseId) {
            if (!toWarehouseId) return allProducts;

            const warehouse = warehouses.find(w => w.id == toWarehouseId);
            if (!warehouse || warehouse.type !== 'consumer') return allProducts;

            // For consumer warehouses, filter by category type based on warehouse name
            const warehouseName = warehouse.name.toLowerCase();
            if (warehouseName.includes('bar')) {
                return allProducts.filter(p => p.category && p.category.type === 'drink');
            } else if (warehouseName.includes('kitchen')) {
                return allProducts.filter(p => p.category && p.category.type === 'food');
            }

            return allProducts;
        }

        function itemsFor(type, toWarehouseId) {
            return type === 'ingredient' ? allIngredients : getFilteredProducts(toWarehouseId);
        }

        function findItem(type, id) {
            const list = type === 'ingredient' ? allIngredients : allProducts;
            return list.find(i => i.id == id);
        }

        function baseUnitLabel(type, item) {
            if (!item) return type === 'ingredient' ? 'unit' : 'bottle';
            return type === 'ingredient' ? (item.unit_name || 'kg') : (item.base_unit || 'bottle');
        }

        function rowEl(index) {
            return document.querySelector(`.item-row[data-index="${index}"]`);
        }

        function addItemRow() {
            const container = document.getElementById('items-list');
            const toWarehouseId = document.querySelector('select[name="to_warehouse_id"]')?.value;
            const i = idx++;

            const div = document.createElement('div');
            div.className = 'item-row p-4 bg-gray-50 dark:bg-gray-900/50 rounded-xl border border-gray-200 dark:border-gray-700 hover:border-blue-300 dark:hover:border-blue-600 transition-all animate-fadeIn';
            div.dataset.index = i;
            div.innerHTML = `
                <div class="md:flex md:gap-3 md:items-center">
                    <select class="type-select px-3 py-3 min-h-[48px] rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 mb-3 md:mb-0">
                        <option value="product">Product</option>
                        <option value="ingredient">Ingredient</option>
                    </select>
                    <div class="md:flex-1 w-full md:w-auto mb-3 md:mb-0">
                        <input type="text" placeholder="Search item…"
                            class="item-search w-full px-4 py-3 min-h-[48px] rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 transition-all outline-none" />
                        <div class="item-results mt-1 max-h-48 overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-700 divide-y divide-gray-100 dark:divide-gray-800 hidden"></div>
                        <div class="item-selected hidden mt-1 flex items-center justify-between gap-2 px-3 py-2 min-h-[48px] rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50">
                            <span class="item-selected-name text-sm font-semibold text-gray-900 dark:text-white truncate"></span>
                            <button type="button" class="shrink-0 text-xs font-bold text-blue-600 dark:text-blue-400 touch-manipulation" onclick="clearItemSelection(this)">Change</button>
                        </div>
                        <select class="item-select hidden"></select>
                    </div>
                    <button type="button" class="px-4 py-3 min-h-[48px] text-sm font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-all touch-manipulation" onclick="removeItemRow(this)">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                        </svg>
                    </button>
                </div>
                <div class="flex flex-wrap gap-3 items-center mt-3">
                    <div class="flex items-center gap-1.5">
                        <button type="button" class="qty-dec w-11 h-11 min-w-[44px] rounded-lg bg-gray-200 dark:bg-gray-700 text-xl font-bold text-gray-700 dark:text-gray-200 touch-manipulation">&minus;</button>
                        <input type="number" min="0.01" step="0.01" value="1" placeholder="Qty" inputmode="decimal" class="qty-input w-20 min-h-[44px] px-2 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-center font-medium" />
                        <button type="button" class="qty-inc w-11 h-11 min-w-[44px] rounded-lg bg-gray-200 dark:bg-gray-700 text-xl font-bold text-gray-700 dark:text-gray-200 touch-manipulation">+</button>
                    </div>
                    <select class="unit-select px-3 py-2 min-h-[44px] rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm"></select>
                    <span class="conversion-note text-xs text-gray-500 dark:text-gray-400"></span>
                </div>
            `;
            container.appendChild(div);
            refreshItemSelect(i);
            refreshUnitSelect(i);
            refreshPreview();
        }

        // The hidden .item-select stays the actual source of truth for the
        // chosen item id — every other function (rowData, availability
        // lookups, submit) already reads it, unchanged. The search box +
        // tap-result list is purely a friendlier way to set its value and
        // fire the same 'change' event a real <select> pick would have.
        function refreshItemSelect(i) {
            const row = rowEl(i);
            if (!row) return;
            const type = row.querySelector('.type-select').value;
            const toWarehouseId = document.querySelector('select[name="to_warehouse_id"]')?.value;
            const select = row.querySelector('.item-select');
            const currentValue = select.value;
            const list = itemsFor(type, toWarehouseId);
            select.innerHTML = '<option value="">Select ' + (type === 'ingredient' ? 'Ingredient' : 'Product') + '</option>' +
                list.map(item => `<option value="${item.id}" ${item.id == currentValue ? 'selected' : ''}>${item.name}</option>`).join('');
            select.dataset.list = JSON.stringify(list.map(item => ({ id: item.id, name: item.name })));
            syncItemPickerUi(row);
        }

        // Reflects the hidden select's current value into the search/result/
        // selected-chip UI — called after refreshItemSelect() and whenever
        // the selection is cleared.
        function syncItemPickerUi(row) {
            const select = row.querySelector('.item-select');
            const searchInput = row.querySelector('.item-search');
            const resultsEl = row.querySelector('.item-results');
            const selectedEl = row.querySelector('.item-selected');
            const selectedNameEl = row.querySelector('.item-selected-name');
            const list = JSON.parse(select.dataset.list || '[]');
            const chosen = select.value ? list.find(item => item.id == select.value) : null;

            if (chosen) {
                selectedNameEl.textContent = chosen.name;
                selectedEl.classList.remove('hidden');
                searchInput.classList.add('hidden');
                resultsEl.classList.add('hidden');
            } else {
                selectedEl.classList.add('hidden');
                searchInput.classList.remove('hidden');
            }
        }

        function renderItemResults(row) {
            const select = row.querySelector('.item-select');
            const searchInput = row.querySelector('.item-search');
            const resultsEl = row.querySelector('.item-results');
            const list = JSON.parse(select.dataset.list || '[]');
            const term = searchInput.value.trim().toLowerCase();

            if (!term) { resultsEl.classList.add('hidden'); resultsEl.innerHTML = ''; return; }

            const matches = list.filter(item => item.name.toLowerCase().includes(term)).slice(0, 20);
            resultsEl.innerHTML = matches.map(item =>
                `<button type="button" class="item-result-btn w-full text-left px-3 py-2 min-h-[44px] text-sm text-gray-800 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800 touch-manipulation" data-id="${item.id}">${item.name}</button>`
            ).join('') || '<p class="px-3 py-2 text-sm text-gray-400">No match.</p>';
            resultsEl.classList.remove('hidden');
        }

        function pickItemResult(row, id) {
            const select = row.querySelector('.item-select');
            select.value = id;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            row.querySelector('.item-search').value = '';
            row.querySelector('.item-results').classList.add('hidden');
            syncItemPickerUi(row);
        }

        function clearItemSelection(btn) {
            const row = btn.closest('.item-row');
            const select = row.querySelector('.item-select');
            select.value = '';
            select.dispatchEvent(new Event('change', { bubbles: true }));
            syncItemPickerUi(row);
        }

        function refreshUnitSelect(i) {
            const row = rowEl(i);
            if (!row) return;
            const type = row.querySelector('.type-select').value;
            const itemId = row.querySelector('.item-select').value;
            const item = findItem(type, itemId);
            const unitSelect = row.querySelector('.unit-select');
            const base = baseUnitLabel(type, item);

            let html = `<option value="base_unit">${base}</option>`;
            if (item && item.units_per_purchase_unit) {
                html += `<option value="purchase_unit">${item.purchase_unit_name || 'pack'} (${item.units_per_purchase_unit} ${base})</option>`;
            }
            unitSelect.innerHTML = html;
        }

        function removeItemRow(btn) {
            const row = btn.closest('.item-row');
            if (row) {
                row.classList.add('animate-fadeOut');
                setTimeout(() => {
                    row.remove();
                    refreshPreview();
                }, 200);
            }
        }

        function rowData(row) {
            const type = row.querySelector('.type-select').value;
            const itemId = row.querySelector('.item-select').value;
            const item = findItem(type, itemId);
            const enteredQty = parseFloat(row.querySelector('.qty-input').value) || 0;
            const enteredUnit = row.querySelector('.unit-select').value;
            const unitsPerPurchaseUnit = (item && item.units_per_purchase_unit) || null;
            const baseQty = (enteredUnit === 'purchase_unit' && unitsPerPurchaseUnit)
                ? Math.round(enteredQty * unitsPerPurchaseUnit * 100) / 100
                : Math.round(enteredQty * 100) / 100;

            return { type, itemId, item, enteredQty, enteredUnit, baseQty };
        }

        async function refreshPreview() {
            const rows = document.querySelectorAll('#items-list > .item-row');
            const body = document.getElementById('preview-body');
            const mobileBody = document.getElementById('mobile-preview-body');
            const fromWarehouseId = document.querySelector('select[name="from_warehouse_id"]')?.value;

            body.innerHTML = '';
            mobileBody.innerHTML = '';
            let total = 0;

            for (const r of rows) {
                const { type, itemId, item, enteredQty, enteredUnit, baseQty } = rowData(r);
                const name = item ? item.name : '';
                const noteEl = r.querySelector('.conversion-note');
                const base = baseUnitLabel(type, item);
                noteEl.textContent = enteredUnit === 'purchase_unit' ? `= ${baseQty} ${base}` : '';
                total += baseQty;

                let availability = '-';
                const cacheKey = `${type}:${itemId}`;
                if (fromWarehouseId && itemId) {
                    try {
                        const url = type === 'ingredient'
                            ? `/warehouses/${fromWarehouseId}/ingredient/${itemId}/quantity`
                            : `/warehouses/${fromWarehouseId}/product/${itemId}/quantity`;
                        const response = await fetch(url);
                        const data = await response.json();
                        availability = data.quantity ?? 0;
                        availabilityCache[cacheKey] = availability;
                    } catch (e) {
                        availability = '-';
                        availabilityCache[cacheKey] = 0;
                    }
                }

                const tr = document.createElement('tr');
                tr.className = 'border-t border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-900/50 transition-colors';
                tr.innerHTML = `
                    <td class="py-3 px-2 font-medium text-gray-900 dark:text-gray-100">${name}</td>
                    <td class="py-3 px-2 text-center font-semibold text-blue-600 dark:text-blue-400">${enteredQty}</td>
                    <td class="py-3 px-2 text-center text-gray-600 dark:text-gray-400">${baseQty}</td>
                    <td class="py-3 px-2 text-center text-gray-600 dark:text-gray-400">${availability}</td>
                `;
                body.appendChild(tr);

                const card = document.createElement('div');
                card.className = 'bg-gray-50 dark:bg-gray-800/50 rounded-lg p-3 border border-gray-200 dark:border-gray-700';
                card.innerHTML = `
                    <div class="flex-1">
                        <h4 class="font-medium text-gray-900 dark:text-white text-sm">${name}</h4>
                        <div class="flex items-center gap-2 mt-1 flex-wrap">
                            <span class="px-2 py-1 bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded text-xs font-semibold">= ${baseQty} ${base}</span>
                            <span class="px-2 py-1 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded text-xs font-semibold">Avail: ${availability}</span>
                        </div>
                    </div>
                `;
                mobileBody.appendChild(card);
            }

            document.getElementById('preview-count').textContent = `${rows.length} line${rows.length !== 1 ? 's' : ''}`;
        }

        document.addEventListener('input', function (e) {
            if (e.target && (e.target.matches('.qty-input') || e.target.matches('select[name="from_warehouse_id"]'))) {
                refreshPreview();
            }
            if (e.target && e.target.matches('.item-search')) {
                const row = e.target.closest('.item-row');
                if (row) renderItemResults(row);
            }
        });

        document.addEventListener('click', function (e) {
            const resultBtn = e.target.closest && e.target.closest('.item-result-btn');
            if (resultBtn) {
                const row = resultBtn.closest('.item-row');
                if (row) pickItemResult(row, resultBtn.dataset.id);
                return;
            }

            const decBtn = e.target.closest && e.target.closest('.qty-dec');
            const incBtn = e.target.closest && e.target.closest('.qty-inc');
            if (decBtn || incBtn) {
                const row = (decBtn || incBtn).closest('.item-row');
                if (!row) return;
                const input = row.querySelector('.qty-input');
                const step = parseFloat(input.step) || 1;
                const current = parseFloat(input.value) || 0;
                const next = decBtn ? Math.max(parseFloat(input.min) || 0, current - step) : current + step;
                input.value = Math.round(next * 100) / 100;
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });

        document.addEventListener('change', function (e) {
            const row = e.target.closest && e.target.closest('.item-row');

            if (e.target.matches('select[name="from_warehouse_id"]')) {
                Object.keys(availabilityCache).forEach(key => delete availabilityCache[key]);
                refreshPreview();
            }
            if (e.target.matches('select[name="to_warehouse_id"]')) {
                document.querySelectorAll('.item-row').forEach(r => refreshItemSelect(parseInt(r.dataset.index)));
                refreshPreview();
            }
            if (row && e.target.matches('.type-select')) {
                row.querySelector('.item-select').value = '';
                refreshItemSelect(parseInt(row.dataset.index));
                refreshUnitSelect(parseInt(row.dataset.index));
                refreshPreview();
            }
            if (row && e.target.matches('.item-select')) {
                refreshUnitSelect(parseInt(row.dataset.index));
                refreshPreview();
            }
            if (row && e.target.matches('.unit-select')) {
                refreshPreview();
            }
        });

        addItemRow(); // start with one row

        // Delegated (like every other handler above) rather than attached
        // directly to the #transfer-form element it was found at script-run
        // time — wire:init="load" fires a Livewire re-render once the
        // deferred "Recent Transfers" section loads, and Livewire's DOM
        // morph doesn't know about the item rows this script injected
        // client-side into #items-list, so it can replace the form node
        // (or an ancestor of it) rather than patch it in place. A listener
        // attached to that specific node instance is silently lost when
        // that happens, so the very next click on "Create Transfer" falls
        // through to the browser's own native form submission instead —
        // a real page navigation straight to /stock-transfers with a stale
        // CSRF token, landing on Laravel's raw 419 page. A listener on
        // document survives any number of re-renders, since document
        // itself is never replaced.
        document.addEventListener('submit', function (e) {
            if (!e.target || e.target.id !== 'transfer-form') return;

            e.preventDefault();

            const rows = document.querySelectorAll('#items-list > .item-row');
            const items = [];
            const ingredientItems = [];
            const errorMessages = [];

            for (const r of rows) {
                const { type, itemId, item, enteredQty, enteredUnit, baseQty } = rowData(r);
                if (!itemId) continue;

                const available = availabilityCache[`${type}:${itemId}`] ?? 0;
                if (baseQty > available) {
                    errorMessages.push(`Cannot transfer ${baseQty} ${baseUnitLabel(type, item)} of "${item.name}" - only ${available} available`);
                }

                const line = { entered_qty: enteredQty, entered_unit: enteredUnit };
                if (type === 'ingredient') {
                    line.ingredient_id = parseInt(itemId);
                    ingredientItems.push(line);
                } else {
                    line.product_id = parseInt(itemId);
                    items.push(line);
                }
            }

            if (items.length === 0 && ingredientItems.length === 0) {
                errorMessages.push('Add at least one line.');
            }

            const errorDiv = document.getElementById('form-errors');
            if (errorMessages.length > 0) {
                errorDiv.innerHTML = errorMessages.map(msg => `<p>${msg}</p>`).join('');
                errorDiv.classList.remove('hidden');
                showToast('Please fix the errors before submitting', 'error');
                return;
            }
            errorDiv.classList.add('hidden');

            const payload = {
                from_warehouse_id: document.querySelector('select[name="from_warehouse_id"]').value,
                to_warehouse_id: document.querySelector('select[name="to_warehouse_id"]').value,
                items,
                ingredient_items: ingredientItems,
            };

            fetch('/stock-transfers', {
                method: 'POST',
                body: JSON.stringify(payload),
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                }
            })
            .then(response => {
                // A real 419 (session/CSRF expired) comes back as Laravel's
                // own HTML error page, not JSON — parsing that as JSON would
                // throw and fall into the generic catch() below with a
                // misleading "Error creating transfer" message instead of
                // the actual, actionable reason.
                if (response.status === 419) {
                    throw new Error('SESSION_EXPIRED');
                }
                return response.json();
            })
            .then(data => {
                if (data.message && data.message === 'Forbidden') {
                    showToast('Permission denied', 'error');
                } else if (data.transfer_number) {
                    showToast('Transfer created successfully!');
                    document.getElementById('items-list').innerHTML = '';
                    idx = 0;
                    addItemRow();
                    Object.keys(availabilityCache).forEach(key => delete availabilityCache[key]);
                    refreshPreview();
                    setTimeout(() => location.reload(), 2000);
                } else {
                    showToast(data.message || 'Error creating transfer', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                if (error && error.message === 'SESSION_EXPIRED') {
                    showToast('Your session expired — reload the page and try again', 'error');
                    return;
                }
                showToast('Error creating transfer', 'error');
            });
        });

        function showToast(msg, type = 'success') {
            const toast = document.getElementById('toast');
            const content = document.getElementById('toast-content');
            const bgClass = type === 'success' 
                ? 'bg-gradient-to-r from-green-500 to-green-600' 
                : 'bg-gradient-to-r from-red-500 to-red-600';
            content.className = `${bgClass} text-white px-6 py-4 rounded-xl shadow-2xl font-medium backdrop-blur-sm`;
            content.textContent = msg;
            toast.classList.remove('hidden');
            toast.classList.add('animate-slideIn');
            setTimeout(() => {
                toast.classList.add('animate-fadeOut');
                setTimeout(() => toast.classList.add('hidden'), 300);
            }, 3000);
        }
    </script>
    @endpush

    @push('styles')
    <style>
        @keyframes slideIn {
            from {
                transform: translateX(400px);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeOut {
            from {
                opacity: 1;
                transform: scale(1);
            }
            to {
                opacity: 0;
                transform: scale(0.95);
            }
        }

        .animate-slideIn {
            animation: slideIn 0.3s ease-out;
        }

        .animate-fadeIn {
            animation: fadeIn 0.3s ease-out;
        }

        .animate-fadeOut {
            animation: fadeOut 0.2s ease-out;
        }
    </style>
    @endpush
</div>