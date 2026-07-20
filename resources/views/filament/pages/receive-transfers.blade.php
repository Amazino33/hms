
<div wire:init="load" class="min-h-screen p-6 bg-gradient-to-br from-gray-50 to-gray-100 dark:from-gray-900 dark:to-gray-950">
    <!-- Toast notifications -->
    <div id="toast" class="fixed top-6 right-6 z-50 hidden">
        <div class="px-6 py-4 rounded-xl shadow-2xl backdrop-blur-sm transform transition-all duration-300" id="toast-content"></div>
    </div>

    <!-- Header -->
    <div class="max-w-7xl mx-auto mb-8">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div>
                    <h2 class="text-3xl font-bold bg-gradient-to-r from-gray-900 to-gray-700 dark:from-white dark:to-gray-300 bg-clip-text text-transparent">Incoming Transfers</h2>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Review and confirm incoming stock transfers</p>
                </div>
            </div>
            <div class="hidden md:block px-4 py-2 bg-white dark:bg-gray-800 rounded-xl shadow-md border border-gray-200 dark:border-gray-700">
                <span class="text-sm text-gray-600 dark:text-gray-400">Warehouse:</span>
                <span class="ml-2 font-semibold text-gray-900 dark:text-white">{{ $warehouseName ?? '—' }}</span>
            </div>
        </div>
        <!-- Mobile Warehouse Badge -->
        <div class="md:hidden mt-4 px-4 py-2 bg-white dark:bg-gray-800 rounded-xl shadow-md border border-gray-200 dark:border-gray-700">
            <span class="text-sm text-gray-600 dark:text-gray-400">Warehouse:<br></span>
            <span class="font-semibold text-gray-900 dark:text-white">All Warehouses</span>
        </div>
    </div>

    <div class="max-w-7xl mx-auto">
        @if(isset($error))
            <div class="bg-gradient-to-r from-red-50 to-pink-50 dark:from-red-900/20 dark:to-pink-900/20 rounded-2xl p-6 border border-red-200 dark:border-red-800 shadow-lg mb-6">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-red-100 dark:bg-red-900/30 rounded-xl flex items-center justify-center">
                        <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                        </svg>
                    </div>
                    <div>
                        <h3 class="font-semibold text-red-800 dark:text-red-200">{{ $error }}</h3>
                    </div>
                </div>
            </div>
        @elseif(!$warehouseId)
            <div class="bg-gradient-to-r from-yellow-50 to-amber-50 dark:from-yellow-900/20 dark:to-amber-900/20 rounded-2xl p-6 border border-yellow-200 dark:border-yellow-800 shadow-lg">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-yellow-100 dark:bg-yellow-900/30 rounded-xl flex items-center justify-center">
                        <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                        </svg>
                    </div>
                    <div>
                        <h3 class="font-semibold text-yellow-800 dark:text-yellow-200">No Warehouse Assigned</h3>
                        <p class="text-sm text-yellow-700 dark:text-yellow-300">Your role is not mapped to a warehouse. Contact an administrator.</p>
                    </div>
                </div>
            </div>
        @else
            @if($transfers->isEmpty())
                <div class="bg-white dark:bg-gray-800 rounded-2xl p-12 border border-gray-200 dark:border-gray-700 shadow-xl text-center">
                    <div class="w-20 h-20 bg-gradient-to-br from-gray-100 to-gray-200 dark:from-gray-700 dark:to-gray-600 rounded-full flex items-center justify-center mx-auto mb-6">
                        <svg class="w-10 h-10 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                        </svg>
                    </div>
                    <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">No Incoming Transfers</h3>
                    <p class="text-gray-600 dark:text-gray-400">There are no pending transfers waiting for your confirmation.</p>
                </div>
            @else
                <!-- Stats Bar -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 shadow-md">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900/30 rounded-lg flex items-center justify-center">
                                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                </svg>
                            </div>
                            <div>
                                <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $transfers->count() }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Pending Transfers</p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 shadow-md">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-green-100 dark:bg-green-900/30 rounded-lg flex items-center justify-center">
                                <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                </svg>
                            </div>
                            <div>
                                <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $transfers->sum(fn($t) => $t->items->count() + $t->ingredientItems->count()) }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Total Lines</p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 shadow-md">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-purple-100 dark:bg-purple-900/30 rounded-lg flex items-center justify-center">
                                <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                </svg>
                            </div>
                            <div>
                                <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $transfers->unique('from_warehouse_id')->count() }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Source Locations</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bulk Actions Bar -->
                <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 shadow-md mb-6">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" id="select-all" class="w-4 h-4 text-indigo-600 bg-gray-100 border-gray-300 rounded focus:ring-indigo-500 dark:focus:ring-indigo-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600">
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Select All</span>
                            </label>
                            <span id="selected-count" class="text-sm text-gray-500 dark:text-gray-400">0 selected</span>
                        </div>
                        <button id="bulk-receive-btn" 
                            class="px-4 py-2 bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white rounded-lg font-medium shadow-md hover:shadow-lg transform hover:scale-105 transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none flex items-center gap-2"
                            disabled>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            Bulk Receive
                        </button>
                    </div>
                </div>

                <!-- Transfer Cards -->
                <div class="space-y-4">
                    @foreach($transfers as $t)
                        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl border border-gray-200 dark:border-gray-700 overflow-hidden hover:shadow-2xl transition-all duration-300">
                            <!-- Card Header -->
                            <div class="p-4 md:p-6 bg-gradient-to-r from-slate-50 to-gray-100 dark:from-slate-800 dark:to-slate-900 border-b border-gray-100 dark:border-gray-700 relative">
                                <!-- Single Checkbox for both layouts -->
                                <input type="checkbox" class="transfer-checkbox w-4 h-4 text-indigo-600 bg-gray-100 border-gray-300 rounded focus:ring-indigo-500 dark:focus:ring-indigo-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600 absolute top-4 left-4 z-10" value="{{ $t->id }}" data-transfer-number="{{ $t->transfer_number }}">
                                
                                <!-- Desktop Layout -->
                                <div class="hidden md:flex items-center justify-between">
                                    <div class="flex items-center gap-4 ml-8">
                                        <div class="w-12 h-12 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-xl flex items-center justify-center shadow-lg">
                                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                                            </svg>
                                        </div>
                                        <div>
                                            <h3 class="text-lg font-bold text-gray-900 dark:text-white">{{ $t->transfer_number }}</h3>
                                            <div class="flex items-center gap-2 mt-1">
                                                <span class="text-sm text-gray-500 dark:text-gray-400">From:</span>
                                                <span class="px-2 py-0.5 bg-gray-100 dark:bg-gray-700 rounded-md text-sm font-medium text-gray-700 dark:text-gray-300">{{ $t->fromWarehouse->name ?? $t->from_warehouse_id }}</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-4">
                                        <div class="text-right">
                                            <p class="text-2xl font-bold text-indigo-600 dark:text-indigo-400">{{ $t->items->count() + $t->ingredientItems->count() }}</p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">Lines</p>
                                        </div>
                                        <span class="px-3 py-1 text-xs font-semibold rounded-full
                                            @if($t->status === 'partially_received') bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-300
                                            @else bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300 @endif">
                                            {{ $t->status === 'partially_received' ? 'Partially received' : ucfirst($t->status) }}
                                        </span>
                                    </div>
                                </div>

                                <!-- Mobile Layout -->
                                <div class="md:hidden flex flex-col gap-4">
                                    <div class="flex items-center gap-4 ml-8">
                                        <div class="w-12 h-12 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-xl flex items-center justify-center shadow-lg">
                                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                                            </svg>
                                        </div>
                                        <div class="flex-1">
                                            <h3 class="text-lg font-bold text-gray-900 dark:text-white">{{ $t->transfer_number }}</h3>
                                            <div class="flex items-center gap-2 mt-1">
                                                <span class="text-sm text-gray-500 dark:text-gray-400">From:</span>
                                                <span class="px-2 py-0.5 bg-gray-100 dark:bg-gray-700 rounded-md text-sm font-medium text-gray-700 dark:text-gray-300">{{ $t->fromWarehouse->name ?? $t->from_warehouse_id }}</span>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <p class="text-xl font-bold text-indigo-600 dark:text-indigo-400">{{ $t->items->count() + $t->ingredientItems->count() }}</p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">Lines</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Items Table -->
                            <div class="p-4">
                                {{-- Per-line receipt: an editable "received" quantity, pre-filled with
                                     the sent amount and clamped to it, submitted independently per
                                     line so a short/damaged line doesn't block the rest of the
                                     transfer. A short receipt opens a manager-visible discrepancy
                                     instead of throwing. --}}
                                @php
                                    $lines = $t->items->map(fn ($it) => ['item' => $it, 'type' => 'product', 'name' => $it->product->name ?? $it->product_id])
                                        ->concat($t->ingredientItems->map(fn ($it) => ['item' => $it, 'type' => 'ingredient', 'name' => $it->ingredient->name ?? $it->ingredient_id]));
                                @endphp

                                <!-- Desktop Table -->
                                <div class="hidden md:block">
                                    <div class="overflow-x-auto hms-table-scroll">
                                        <table class="w-full">
                                            <thead>
                                                <tr class="text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                                    <th class="pb-4 pl-4">Item</th>
                                                    <th class="pb-4 text-center">Sent</th>
                                                    <th class="pb-4 text-center">Received</th>
                                                    <th class="pb-4 pr-4 text-right">Status</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                                @foreach($lines as $line)
                                                    @php $it = $line['item']; @endphp
                                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors"
                                                        @if ($it->isPending()) x-data="{ receivedQty: {{ (float) $it->quantity }} }" @endif>
                                                        <td class="py-4 pl-4">
                                                            <span class="font-medium text-gray-900 dark:text-white">{{ $line['name'] }}</span>
                                                        </td>
                                                        <td class="py-4 text-center">
                                                            <span class="px-3 py-1 bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded-full font-semibold">{{ $it->quantity }}</span>
                                                        </td>
                                                        <td class="py-4 text-center">
                                                            @if ($it->isPending())
                                                                {{-- x-model (not a raw DOM query embedded in wire:click) — Livewire's
                                                                     expression evaluator treats bare identifiers like `document` as
                                                                     component scope lookups, not real browser globals, so
                                                                     `document.getElementById(...)` inside a wire:click argument throws
                                                                     "$wire.document.getElementById is not a function" instead of ever
                                                                     calling the server. This is exactly why "Receive" silently did
                                                                     nothing. --}}
                                                                <input type="number" x-model.number="receivedQty" min="0" max="{{ $it->quantity }}" step="0.01" class="w-24 px-2 py-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-center">
                                                            @else
                                                                <span class="text-gray-600 dark:text-gray-400">{{ $it->received_quantity }}</span>
                                                            @endif
                                                        </td>
                                                        <td class="py-4 pr-4 text-right">
                                                            @if ($it->isPending())
                                                                <button @click="$wire.call('receiveLine', {{ $it->id }}, '{{ $line['type'] }}', receivedQty)" wire:loading.attr="disabled" class="px-3 py-1.5 bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white rounded-lg text-xs font-semibold">Receive</button>
                                                            @elseif ($it->outcome === 'received_full')
                                                                <span class="px-3 py-1 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300 rounded-full text-xs font-semibold">Received</span>
                                                            @elseif ($it->outcome === 'received_short')
                                                                <span class="px-3 py-1 bg-orange-100 dark:bg-orange-900/30 text-orange-700 dark:text-orange-300 rounded-full text-xs font-semibold">Short</span>
                                                            @else
                                                                <span class="px-3 py-1 bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 rounded-full text-xs font-semibold">Rejected</span>
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <!-- Mobile Card Layout -->
                                <div class="md:hidden space-y-4">
                                    @foreach($lines as $line)
                                        @php $it = $line['item']; @endphp
                                        <div class="bg-gray-50 dark:bg-gray-800/50 rounded-xl p-3 border border-gray-200 dark:border-gray-700">
                                            <h4 class="font-medium text-gray-900 dark:text-white text-sm leading-tight mb-2">{{ $line['name'] }}</h4>
                                            <div class="flex items-center gap-2 mb-2">
                                                <span class="px-3 py-1 bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded-lg text-xs font-semibold">Sent: {{ $it->quantity }}</span>
                                            </div>
                                            @if ($it->isPending())
                                                <div x-data="{ receivedQty: {{ (float) $it->quantity }} }" class="space-y-2">
                                                    <x-mobile.stepper model="receivedQty" :min="0" :max="(float) $it->quantity" :step="1" />
                                                    <button @click="$wire.call('receiveLine', {{ $it->id }}, '{{ $line['type'] }}', receivedQty)" wire:loading.attr="disabled"
                                                        class="w-full min-h-[44px] px-3 py-2 bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white rounded-lg text-sm font-semibold touch-manipulation">Receive</button>
                                                </div>
                                            @elseif ($it->outcome === 'received_full')
                                                <span class="px-3 py-1 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300 rounded-lg text-xs font-semibold">Received {{ $it->received_quantity }}</span>
                                            @elseif ($it->outcome === 'received_short')
                                                <span class="px-3 py-1 bg-orange-100 dark:bg-orange-900/30 text-orange-700 dark:text-orange-300 rounded-lg text-xs font-semibold">Short — received {{ $it->received_quantity }}</span>
                                            @else
                                                <span class="px-3 py-1 bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 rounded-lg text-xs font-semibold">Rejected</span>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        @endif
    </div>

    @push('scripts')
    <script>
        let selectedTransfers = [];

        function showToast(msg, type = 'success') {
            const toast = document.getElementById('toast');
            const content = document.getElementById('toast-content');
            const bgClass = type === 'success' 
                ? 'bg-gradient-to-r from-green-500 to-emerald-600' 
                : 'bg-gradient-to-r from-red-500 to-red-600';
            content.className = `${bgClass} text-white px-6 py-4 rounded-xl shadow-2xl font-medium`;
            content.textContent = msg;
            toast.classList.remove('hidden');
            toast.classList.add('animate-slideIn');
            setTimeout(() => {
                toast.classList.add('hidden');
                toast.classList.remove('animate-slideIn');
            }, 4000);
        }

        // Bulk selection functionality — the bulk-actions bar (and these two
        // elements) only exists in markup when there's at least one transfer
        // to show. This ran unconditionally on DOMContentLoaded, so the
        // empty-transfers state threw "Cannot set properties of null"
        // on every page load, before either delegated listener below could
        // register.
        function updateSelectedCount() {
            const selectedCountEl = document.getElementById('selected-count');
            const bulkReceiveBtn = document.getElementById('bulk-receive-btn');
            if (!selectedCountEl || !bulkReceiveBtn) return;

            const checkboxes = document.querySelectorAll('.transfer-checkbox:checked');
            selectedTransfers = Array.from(checkboxes).map(cb => parseInt(cb.value));
            const count = selectedTransfers.length;
            selectedCountEl.textContent = `${count} selected`;
            bulkReceiveBtn.disabled = count === 0;
        }

        function submitBulkReceive() {
            if (selectedTransfers.length === 0) return;

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ||
                document.querySelector('input[name="_token"]')?.value || '';

            showToast('Processing bulk receive...', 'success');

            fetch('/stock-transfers/bulk-receive', {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': csrfToken,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    transfer_ids: selectedTransfers
                })
            }).then(r => r.json()).then(data => {
                const successful = data.successful || 0;
                const failed = data.failed || 0;

                if (failed === 0) {
                    showToast(`Successfully received ${successful} transfers!`);
                } else if (successful === 0) {
                    showToast(`Failed to receive ${failed} transfers. Check console for details.`, 'error');
                    console.error('Bulk receive errors:', data.errors);
                } else {
                    showToast(`Received ${successful} transfers, ${failed} failed. Check console for details.`, 'error');
                    console.error('Bulk receive errors:', data.errors);
                }

                setTimeout(() => location.reload(), 2000);
            }).catch(e => {
                console.error(e);
                showToast('Error processing bulk receive', 'error');
            });
        }

        // Initialize event listeners
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded, initializing event listeners...');
            
            // Individual checkboxes + select-all (delegated so they work after wire:init re-renders)
            document.addEventListener('change', function(e) {
                if (e.target.id === 'select-all') {
                    const checkboxes = document.querySelectorAll('.transfer-checkbox');
                    checkboxes.forEach(cb => { cb.checked = e.target.checked; });
                    updateSelectedCount();
                    const label = e.target.nextElementSibling;
                    if (label) label.textContent = e.target.checked ? 'Deselect All' : 'Select All';
                }
                if (e.target.classList.contains('transfer-checkbox')) {
                    updateSelectedCount();
                    const allCheckboxes = document.querySelectorAll('.transfer-checkbox');
                    const checkedCheckboxes = document.querySelectorAll('.transfer-checkbox:checked');
                    const selectAll = document.getElementById('select-all');
                    if (selectAll) {
                        selectAll.checked = allCheckboxes.length === checkedCheckboxes.length && allCheckboxes.length > 0;
                        selectAll.indeterminate = checkedCheckboxes.length > 0 && checkedCheckboxes.length < allCheckboxes.length;
                    }
                }
            });

            // Bulk receive button (delegated)
            document.addEventListener('click', function(e) {
                if (e.target.closest('#bulk-receive-btn')) submitBulkReceive();
            });

            // Initialize count
            updateSelectedCount();
            console.log('Event listeners initialized');
        });

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
        .animate-slideIn {
            animation: slideIn 0.3s ease-out;
        }
    </style>
    @endpush
</div>
