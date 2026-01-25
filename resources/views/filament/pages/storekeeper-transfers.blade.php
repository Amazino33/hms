<div class="min-h-screen p-6 bg-gradient-to-br from-gray-50 to-gray-100 dark:from-gray-900 dark:to-gray-950">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <!-- Toast notifications -->
    <div id="toast" class="fixed top-6 right-6 z-50 hidden">
        <div class="px-6 py-4 rounded-xl shadow-2xl backdrop-blur-sm transform transition-all duration-300" id="toast-content"></div>
    </div>

    <!-- Header -->
    <div class="max-w-7xl mx-auto mb-8">
        <div class="flex items-center gap-3 mb-2">
            <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl flex items-center justify-center shadow-lg">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                </svg>
            </div>
            <div>
                <h2 class="text-3xl font-bold bg-gradient-to-r from-gray-900 to-gray-700 dark:from-white dark:to-gray-300 bg-clip-text text-transparent">Create Stock Transfer</h2>
                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Transfer inventory between warehouse locations</p>
            </div>
        </div>
    </div>

    <div class="max-w-7xl mx-auto grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Form -->
        <div class="col-span-2 bg-white dark:bg-gray-800 rounded-2xl shadow-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
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
                        <div id="items-list" class="space-y-3">
                            <div class="flex gap-3 items-center p-4 bg-gray-50 dark:bg-gray-900/50 rounded-xl border border-gray-200 dark:border-gray-700 hover:border-blue-300 dark:hover:border-blue-600 transition-all">
                                <select data-index="0" name="items[0][product_id]" class="product-select flex-1 px-4 py-3 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 transition-all outline-none">
                                    <option value="">Select Product</option>
                                    @foreach($products as $product)
                                        <option value="{{ $product->id }}">{{ $product->name }}</option>
                                    @endforeach
                                </select>
                                <input data-index="0" name="items[0][quantity]" type="number" min="1" value="1" placeholder="Qty" class="w-24 px-4 py-3 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 transition-all outline-none text-center font-medium" />
                                <button type="button" class="px-4 py-3 text-sm font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-all" onclick="removeItemRow(this)">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <div class="mt-4">
                            <button type="button" onclick="addItemRow()" class="flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-gray-100 to-gray-200 dark:from-gray-700 dark:to-gray-600 hover:from-gray-200 hover:to-gray-300 dark:hover:from-gray-600 dark:hover:to-gray-500 text-gray-800 dark:text-gray-100 rounded-xl font-medium transition-all shadow-sm hover:shadow">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                </svg>
                                Add Item
                            </button>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <button type="submit" class="px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white rounded-xl font-semibold shadow-lg hover:shadow-xl transform hover:scale-105 transition-all duration-200">
                            Create Transfer
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Preview Panel -->
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl border border-gray-200 dark:border-gray-700 overflow-hidden h-fit sticky top-6">
            <div class="p-6 bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-gray-900 dark:to-gray-800 border-b border-gray-200 dark:border-gray-700">
                <div class="flex items-center justify-between">
                    <div class="font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                        <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                        </svg>
                        Transfer Preview
                    </div>
                    <div class="px-3 py-1 bg-blue-600 text-white text-xs font-bold rounded-full" id="preview-count">1 item</div>
                </div>
            </div>

            <div class="p-6">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 dark:text-gray-400 text-xs uppercase font-semibold">
                                <th class="py-3 px-2">Product</th>
                                <th class="py-3 px-2 text-center">Qty</th>
                                <th class="py-3 px-2 text-center">Avail</th>
                            </tr>
                        </thead>
                        <tbody id="preview-body">
                            <tr class="border-t border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-900/50 transition-colors">
                                <td class="py-3 px-2 font-medium text-gray-900 dark:text-gray-100">Product A</td>
                                <td class="py-3 px-2 text-center font-semibold text-blue-600 dark:text-blue-400">1</td>
                                <td class="py-3 px-2 text-center text-gray-600 dark:text-gray-400">-</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Transfers -->
    <div class="max-w-7xl mx-auto mt-6 bg-white dark:bg-gray-800 rounded-2xl shadow-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="p-6 bg-gradient-to-br from-gray-50 to-gray-100 dark:from-gray-900 dark:to-gray-800 border-b border-gray-200 dark:border-gray-700">
            <div class="font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                <svg class="w-4 h-4 text-gray-600 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                Recent Transfers
            </div>
        </div>
        <div class="p-6">
            @if($recentTransfers->count() > 0)
                <div class="space-y-4">
                    @foreach($recentTransfers as $transfer)
                        <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-900/50 rounded-xl border border-gray-200 dark:border-gray-700">
                            <div class="flex items-center gap-4">
                                <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg flex items-center justify-center">
                                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                                    </svg>
                                </div>
                                <div>
                                    <p class="font-semibold text-gray-900 dark:text-white">{{ $transfer->transfer_number }}</p>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">
                                        {{ $transfer->fromWarehouse->name ?? 'Unknown' }} → {{ $transfer->toWarehouse->name ?? 'Unknown' }}
                                        • {{ $transfer->items->sum('quantity') }} items
                                    </p>
                                </div>
                            </div>
                            <div class="text-right">
                                <span class="px-3 py-1 text-xs font-semibold rounded-full 
                                    @if($transfer->status === 'pending') bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300
                                    @elseif($transfer->status === 'sent') bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300
                                    @elseif($transfer->status === 'received') bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300
                                    @else bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-300
                                    @endif">
                                    {{ ucfirst($transfer->status ?? 'unknown') }}
                                </span>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $transfer->created_at->diffForHumans() }}</p>
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
        </div>
    </div>

    <script>
        let idx = 1;
        const allProducts = @json($products);
        const warehouses = @json($warehouses);
        const productAvailability = {}; // Store availability data

        function getFilteredProducts(toWarehouseId) {
            if (!toWarehouseId) return allProducts;
            
            const warehouse = warehouses.find(w => w.id == toWarehouseId);
            if (!warehouse || warehouse.type !== 'consumer') return allProducts;
            
            // For consumer warehouses, filter by category type
            if (toWarehouseId == 4) { // Bar
                return allProducts.filter(p => p.category && p.category.type === 'drink');
            } else if (toWarehouseId == 5) { // Kitchen
                return allProducts.filter(p => p.category && p.category.type === 'food');
            }
            
            return allProducts;
        }

        function updateProductSelects() {
            const toWarehouseSelect = document.querySelector('select[name="to_warehouse_id"]');
            const toWarehouseId = toWarehouseSelect ? toWarehouseSelect.value : null;
            const filteredProducts = getFilteredProducts(toWarehouseId);
            
            // Update all existing product selects
            const productSelects = document.querySelectorAll('.product-select');
            productSelects.forEach(select => {
                const currentValue = select.value;
                select.innerHTML = '<option value="">Select Product</option>' +
                    filteredProducts.map(product => `<option value="${product.id}" ${product.id == currentValue ? 'selected' : ''}>${product.name}</option>`).join('');
            });
        }

        function addItemRow() {
            const container = document.getElementById('items-list');
            const toWarehouseSelect = document.querySelector('select[name="to_warehouse_id"]');
            const toWarehouseId = toWarehouseSelect ? toWarehouseSelect.value : null;
            const filteredProducts = getFilteredProducts(toWarehouseId);
            
            const div = document.createElement('div');
            div.className = 'flex gap-3 items-center p-4 bg-gray-50 dark:bg-gray-900/50 rounded-xl border border-gray-200 dark:border-gray-700 hover:border-blue-300 dark:hover:border-blue-600 transition-all animate-fadeIn';
            div.innerHTML = `
                <select data-index="${idx}" class="product-select flex-1 px-4 py-3 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 transition-all outline-none">
                    <option value="">Select Product</option>
                    ${filteredProducts.map(product => `<option value="${product.id}">${product.name}</option>`).join('')}
                </select>
                <input data-index="${idx}" type="number" min="1" value="1" placeholder="Qty" class="w-24 px-4 py-3 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 transition-all outline-none text-center font-medium" />
                <button type="button" class="px-4 py-2.5 text-sm font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-all" onclick="removeItemRow(this)">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                    </svg>
                </button>
            `;
            container.appendChild(div);
            idx++;
            refreshPreview();
        }

        function removeItemRow(btn) {
            const row = btn.closest('div');
            if (row) {
                row.classList.add('animate-fadeOut');
                setTimeout(() => {
                    row.remove();
                    refreshPreview();
                }, 200);
            }
        }

        async function refreshPreview() {
            const rows = document.querySelectorAll('#items-list > div');
            const body = document.getElementById('preview-body');
            const fromWarehouseSelect = document.querySelector('select[name="from_warehouse_id"]');
            const fromWarehouseId = fromWarehouseSelect ? fromWarehouseSelect.value : null;
            
            body.innerHTML = '';
            let total = 0;
            
            for (const r of rows) {
                const sel = r.querySelector('select.product-select');
                const qty = r.querySelector('input[type="number"]');
                const name = sel.options[sel.selectedIndex].text;
                const productId = sel.value;
                const q = parseInt(qty.value) || 0;
                total += q;
                
                let availability = '-';
                if (fromWarehouseId && productId) {
                    try {
                        const response = await fetch(`/warehouses/${fromWarehouseId}/product/${productId}/quantity`);
                        const data = await response.json();
                        availability = data.quantity || 0;
                        // Store availability for validation
                        productAvailability[productId] = availability;
                    } catch (e) {
                        console.error('Error fetching availability:', e);
                        availability = '-';
                        productAvailability[productId] = 0;
                    }
                }
                
                const tr = document.createElement('tr');
                tr.className = 'border-t border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-900/50 transition-colors';
                tr.innerHTML = `
                    <td class="py-3 px-2 font-medium text-gray-900 dark:text-gray-100">${name}</td>
                    <td class="py-3 px-2 text-center font-semibold text-blue-600 dark:text-blue-400">${q}</td>
                    <td class="py-3 px-2 text-center text-gray-600 dark:text-gray-400">${availability}</td>
                `;
                body.appendChild(tr);
            }
            
            document.getElementById('preview-count').textContent = `${total} item${total !== 1 ? 's' : ''}`;
        }

        document.addEventListener('input', function (e) {
            if (e.target && (e.target.matches('select.product-select') || e.target.matches('input[type="number"]') || e.target.matches('select[name="from_warehouse_id"]'))) {
                refreshPreview();
            }
        });

        document.addEventListener('change', function (e) {
            if (e.target && e.target.matches('select[name="from_warehouse_id"]')) {
                // Clear availability data when warehouse changes
                Object.keys(productAvailability).forEach(key => delete productAvailability[key]);
                refreshPreview();
            }
            if (e.target && e.target.matches('select[name="to_warehouse_id"]')) {
                // Update product selects when destination warehouse changes
                updateProductSelects();
                refreshPreview();
            }
        });

        refreshPreview();
        updateProductSelects(); // Update product selects on page load

        document.getElementById('transfer-form').addEventListener('submit', function (e) {
            e.preventDefault();
            
            // Validate quantities against availability
            const rows = document.querySelectorAll('#items-list > div');
            let isValid = true;
            let errorMessages = [];
            
            for (const r of rows) {
                const sel = r.querySelector('select.product-select');
                const qty = r.querySelector('input[type="number"]');
                const productId = sel.value;
                const productName = sel.options[sel.selectedIndex].text;
                const quantity = parseInt(qty.value) || 0;
                const available = productAvailability[productId] || 0;
                
                if (quantity > available) {
                    isValid = false;
                    errorMessages.push(`Cannot transfer ${quantity} of "${productName}" - only ${available} available`);
                }
            }
            
            if (!isValid) {
                // Show error messages
                const errorDiv = document.getElementById('form-errors');
                errorDiv.innerHTML = errorMessages.map(msg => `<p>${msg}</p>`).join('');
                errorDiv.classList.remove('hidden');
                showToast('Please fix the errors before submitting', 'error');
                return;
            }
            
            // Hide any previous errors
            document.getElementById('form-errors').classList.add('hidden');
            
            // Submit the form
            const form = document.getElementById('transfer-form');
            const formData = new FormData(form);
            
            fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.message && data.message === 'Forbidden') {
                    showToast('Permission denied', 'error');
                } else if (data.transfer_number) {
                    showToast('Transfer created successfully!');
                    // Reset form
                    form.reset();
                    // Clear product availability
                    Object.keys(productAvailability).forEach(key => delete productAvailability[key]);
                    // Refresh preview
                    refreshPreview();
                    // Reload recent transfers
                    setTimeout(() => location.reload(), 2000);
                } else {
                    showToast('Error creating transfer', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
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
</div>