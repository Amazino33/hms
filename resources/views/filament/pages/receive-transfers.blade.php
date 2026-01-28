<div class="min-h-screen p-6 bg-gradient-to-br from-gray-50 to-gray-100 dark:from-gray-900 dark:to-gray-950">
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
                                <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $transfers->sum(fn($t) => $t->items->sum('quantity')) }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Total Items</p>
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

                <!-- Transfer Cards -->
                <div class="space-y-4">
                    @foreach($transfers as $t)
                        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl border border-gray-200 dark:border-gray-700 overflow-hidden hover:shadow-2xl transition-all duration-300">
                            <!-- Card Header -->
                            <div class="p-4 md:p-6 bg-gradient-to-r from-slate-50 to-gray-100 dark:from-slate-800 dark:to-slate-900 border-b border-gray-100 dark:border-gray-700">
                                <!-- Desktop Layout -->
                                <div class="hidden md:flex items-center justify-between">
                                    <div class="flex items-center gap-4">
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
                                            <p class="text-2xl font-bold text-indigo-600 dark:text-indigo-400">{{ $t->items->sum('quantity') }}</p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">Total Items</p>
                                        </div>
                                        <button onclick="openReceiveModal({{ $t->id }}, '{{ $t->transfer_number }}')"
                                            class="px-5 py-3 bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white rounded-xl font-semibold shadow-lg hover:shadow-xl transform hover:scale-105 transition-all duration-200 flex items-center gap-2">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                            </svg>
                                            Receive
                                        </button>
                                    </div>
                                </div>

                                <!-- Mobile Layout -->
                                <div class="md:hidden flex flex-col gap-4">
                                    <div class="flex items-center gap-4">
                                        <div class="w-12 h-12 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-xl flex items-center justify-center shadow-lg">
                                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                                            </svg>
                                        </div>
                                        <div class="flex-1">
                                            <h3 class="font-bold text-gray-900 dark:text-white">{{ $t->transfer_number }}</h3>
                                            <div class="flex items-center gap-2 mt-1">
                                                <span class="text-sm text-gray-500 dark:text-gray-400">From:</span>
                                                <span class="px-2 py-0.5 bg-gray-100 dark:bg-gray-700 rounded-md text-sm font-medium text-gray-700 dark:text-gray-300">{{ $t->fromWarehouse->name ?? $t->from_warehouse_id }}</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <div class="text-left">
                                            <p class="text-xs text-gray-500 dark:text-gray-400">Total Items</p>
                                        </div>
                                        <button onclick="openReceiveModal({{ $t->id }}, '{{ $t->transfer_number }}')" 
                                            class="px-5 py-3 bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white rounded-xl font-semibold shadow-lg hover:shadow-xl transform hover:scale-105 transition-all duration-200 flex items-center gap-2">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                            </svg>
                                            Receive
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Items Table -->
                            <div class="p-4">
                                <!-- Desktop Table -->
                                <div class="hidden md:block">
                                    <div class="overflow-x-auto">
                                        <table class="w-full">
                                            <thead>
                                                <tr class="text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                                    <th class="pb-4 pl-4">Product</th>
                                                    <th class="pb-4 text-center">Quantity</th>
                                                    <th class="pb-4 pr-4 text-right">Status</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                                @foreach($t->items as $it)
                                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                                                        <td class="py-4 pl-4">
                                                            <div class="flex items-center gap-3">
                                                                <div class="w-10 h-10 bg-gradient-to-br from-gray-100 to-gray-200 dark:from-gray-700 dark:to-gray-600 rounded-lg flex items-center justify-center">
                                                                    <svg class="w-5 h-5 text-gray-500 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                                                    </svg>
                                                                </div>
                                                                <span class="font-medium text-gray-900 dark:text-white">{{ $it->product->name ?? $it->product_id }}</span>
                                                            </div>
                                                        </td>
                                                        <td class="py-4 text-center">
                                                            <span class="px-3 py-1 bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded-full font-semibold">{{ $it->quantity }}</span>
                                                        </td>
                                                        <td class="py-4 pr-4 text-right">
                                                            <span class="px-3 py-1 bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-300 rounded-full text-xs font-semibold">Pending</span>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <!-- Mobile Card Layout -->
                                <div class="md:hidden space-y-4">
                                    @foreach($t->items as $it)
                                        <div class="bg-gray-50 dark:bg-gray-800/50 rounded-xl p-2 border border-gray-200 dark:border-gray-700">
                                            <div class="flex items-start justify-between">
                                                <div class="flex items-center gap-3 flex-1">
                                                    <div class="w-10 h-10 bg-gradient-to-br from-gray-100 to-gray-200 dark:from-gray-700 dark:to-gray-600 rounded-lg flex items-center justify-center flex-shrink-0">
                                                        <svg class="w-5 h-5 text-gray-500 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                                        </svg>
                                                    </div>
                                                    <div class="flex-1 min-w-0">
                                                        <h4 class="font-medium text-gray-900 dark:text-white text-sm leading-tight">{{ $it->product->name ?? $it->product_id }}</h4>

                                                    </div>
                                                </div>
                                            </div>
                                            <div class="flex items-center gap-2 mt-3">
                                                <span class="px-3 py-1 bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded-lg text-xs font-semibold">Qty: {{ $it->quantity }}</span>
                                                <span class="px-3 py-1 bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-300 rounded-lg text-xs font-semibold">Pending</span>
                                            </div>
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

    <!-- Confirm receive modal -->
    <div id="receive-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/60 backdrop-blur-sm">
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-md mx-4 transform transition-all duration-300 scale-100">
            <div class="p-6 border-b border-gray-100 dark:border-gray-700">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-gradient-to-br from-green-500 to-emerald-600 rounded-xl flex items-center justify-center">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white">Confirm Receipt</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">This action will update inventory</p>
                    </div>
                </div>
            </div>
            <div class="p-6">
                <p id="modal-transfer-number" class="text-gray-700 dark:text-gray-300">Are you sure you want to mark this transfer as received?</p>
            </div>
            <div class="p-6 bg-gray-50 dark:bg-gray-900/50 rounded-b-2xl flex justify-end gap-3">
                <button onclick="closeReceiveModal()" class="px-5 py-2.5 rounded-xl bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-800 dark:text-gray-200 font-medium transition-all">
                    Cancel
                </button>
                <button type="button" onclick="submitReceive()" class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-green-500 to-emerald-600 hover:from-green-600 hover:to-emerald-700 text-white font-semibold shadow-lg hover:shadow-xl transition-all">
                    Yes, Receive Transfer
                </button>
            </div>
        </div>
    </div>

    <script>
        let currentReceiveId = null;

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

        function openReceiveModal(id, number) {
            const modal = document.getElementById('receive-modal');
            const p = document.getElementById('modal-transfer-number');
            currentReceiveId = id;
            p.textContent = `Mark transfer ${number} as received? This will update your inventory.`;
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeReceiveModal() {
            const modal = document.getElementById('receive-modal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            currentReceiveId = null;
        }

        function submitReceive() {
            if (!currentReceiveId) return;
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ||
                document.querySelector('input[name="_token"]')?.value || '';
            fetch(`/stock-transfers/${currentReceiveId}/receive`, {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': csrfToken,
                    'Content-Type': 'application/json'
                }
            }).then(r => r.json()).then(data => {
                if (data.message && data.message.includes('Forbidden')) {
                    showToast('Permission denied', 'error');
                } else if (data.status === 'received') {
                    showToast('Transfer received successfully!');
                    closeReceiveModal();
                    setTimeout(() => location.reload(), 2000);
                } else if (data.message) {
                    showToast(data.message, 'error');
                }
            }).catch(e => {
                console.error(e);
                showToast('Error receiving transfer', 'error');
            });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeReceiveModal();
            }
        });
    </script>

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
</div>
