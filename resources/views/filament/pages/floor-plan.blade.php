<x-filament-panels::page>
    @script
    <script>
        // Built via DOM APIs rather than win.document.write() of a full
        // doc-type/head/body markup string \u2014 that string used to contain
        // its own literal closing body and html tags, and something in
        // the response pipeline (Livewire's own script/asset injection
        // scans rendered HTML for the real page's closing body tag to
        // insert into) matched THAT occurrence instead, splicing a script
        // tag into the middle of this one and breaking it \u2014 this page's
        // wire:poll.1s made it especially exposed. Avoiding that literal
        // tag text entirely removes the hazard regardless of the exact
        // injection mechanism.
        window.printPOSBill = function (d) {
            const win = window.open('', '_blank', 'width=440,height=680,scrollbars=yes,resizable=yes');
            if (!win) { alert('Please allow pop-ups to print the bill.'); return; }

            const rows = (d.items || []).map(i =>
                `<tr><td style="padding:3px 6px;">${i.name}</td><td style="text-align:center;padding:3px 6px;">${i.quantity}</td><td style="text-align:right;padding:3px 6px;">&#8358;${Number(i.price * i.quantity).toLocaleString()}</td></tr>`
            ).join('');

            win.document.title = 'Unpaid Bill \u2013 ' + d.tableName;

            const style = win.document.createElement('style');
            style.textContent = `
                * { margin:0; padding:0; box-sizing:border-box; }
                body { font-family: 'Courier New', monospace; font-size:13px; width:80mm; padding:10px; color:#000; }
                h1 { text-align:center; font-size:16px; letter-spacing:2px; margin-bottom:2px; }
                .sub { text-align:center; font-size:11px; margin-bottom:4px; }
                .dashed { border-top:1px dashed #000; margin:6px 0; }
                .meta { font-size:12px; margin-bottom:2px; }
                table { width:100%; border-collapse:collapse; }
                th { text-align:left; font-size:11px; border-bottom:1px solid #000; padding:2px 6px; }
                .total-row { font-size:15px; font-weight:bold; text-align:right; margin-top:8px; }
                .footer { text-align:center; font-size:10px; margin-top:10px; color:#555; }
                @media print {
                    body { width:auto; }
                    button { display:none; }
                }
            `;
            win.document.head.appendChild(style);

            const logoHtml = (d.company && d.company.logo)
                ? `<div style="text-align:center;margin-bottom:6px;"><img src="${d.company.logo}" style="max-height:60px;max-width:140px;object-fit:contain;" /></div>`
                : '';
            const addressHtml = (d.company && d.company.address) ? `<div class="sub">${d.company.address}</div>` : '';
            const phoneHtml = (d.company && d.company.phone) ? `<div class="sub">${d.company.phone}</div>` : '';
            const companyName = (d.company && d.company.name) ? d.company.name : 'HMS RECEIPT';

            win.document.body.innerHTML = `
                ${logoHtml}
                <h1>${companyName}</h1>
                ${addressHtml}
                ${phoneHtml}
                <div class="sub">*** UNPAID BILL ***</div>
                <div class="dashed"></div>
                <div class="meta">Table : <strong>${d.tableName}</strong></div>
                <div class="meta">Date  : ${d.date}</div>
                <div class="meta">Staff : ${d.cashier}</div>
                <div class="dashed"></div>
                <table>
                    <thead><tr><th>Item</th><th style="text-align:center;">Qty</th><th style="text-align:right;">Amount</th></tr></thead>
                    <tbody>${rows}</tbody>
                </table>
                <div class="dashed"></div>
                <div class="total-row">TOTAL: &#8358;${Number(d.total).toLocaleString()}</div>
                <div class="dashed"></div>
                <div class="footer">Thank you for dining with us!<br>This is NOT a payment receipt.</div>
            `;

            win.focus();
            setTimeout(() => { win.print(); }, 600);
        };
        window.addEventListener('print-bill', e => window.printPOSBill(e.detail[0] ?? e.detail));
    </script>
    @endscript

    <div wire:poll.1s>
        <!-- Page Header -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-3 md:p-4 mx-3 md:mx-6 my-4 md:my-6 mb-6">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 md:w-12 md:h-12 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-lg md:rounded-xl flex items-center justify-center shadow-md">
                        <svg class="w-5 h-5 md:w-6 md:h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                        </svg>
                    </div>
                    <div>
                        <h1 class="text-lg md:text-xl font-bold text-gray-900 dark:text-white">Floor Plan</h1>
                        <p class="text-xs md:text-sm text-gray-500 dark:text-gray-400">Table Management & Order Overview</p>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <div class="flex items-center gap-2 text-sm">
                        <span class="w-2 h-2 bg-green-500 rounded-full"></span>
                        <span class="text-gray-600 dark:text-gray-400">{{ $this->getViewData()['tables']->filter(function($table) { return $table->status === 'available' || ($table->status === 'occupied' && $table->orders->isEmpty()); })->count() }} Available</span>
                    </div>
                    <div class="flex items-center gap-2 text-sm">
                        <span class="w-2 h-2 bg-red-500 rounded-full"></span>
                        <span class="text-gray-600 dark:text-gray-400">{{ $this->getViewData()['tables']->filter(function($table) { return $table->status === 'occupied' && $table->orders->isNotEmpty(); })->count() }} Occupied</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-4">
            @foreach($this->getViewData()['tables'] as $table)
                @php
                    // Get order details
                    $activeOrder = $table->orders->first();
                    $total = $activeOrder ? $activeOrder->total_amount : 0;
                    $orderTime = $activeOrder ? $activeOrder->created_at->diffForHumans() : '';
                    $orderStatus = $activeOrder ? $activeOrder->status : null;

                    $isOccupied = $table->status === 'occupied' && $activeOrder;
                    $isReserved = $table->status === 'reserved';
                    $isCleaning = $table->status === 'cleaning';
                    $isMaintenance = $table->status === 'maintenance';
                    $isAvailable = $table->status === 'available' || (!$activeOrder && $table->status === 'occupied');
                @endphp

                <div class="relative p-3 md:p-4 rounded-xl border shadow-sm transition hover:shadow-md mx-2 my-2 md:mx-0 md:my-0
                    {{ $isOccupied ? 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800 cursor-pointer' :
                       ($isReserved ? 'bg-yellow-50 dark:bg-yellow-900/20 border-yellow-200 dark:border-yellow-800' :
                       ($isCleaning ? 'bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800' :
                       ($isMaintenance ? 'bg-gray-50 dark:bg-gray-900/20 border-gray-200 dark:border-gray-800' :
                       'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800')))}}"
                    @if($isOccupied && $activeOrder && $activeOrder->user_id === auth()->id()) onclick="window.location.href='/admin/table-detail?table_id={{ $table->id }}'" @endif>

                    {{-- Header: Name & Icon --}}
                    <div class="flex justify-between items-start mb-2">
                        <h3 class="text-lg md:text-xl font-black {{
                            $isOccupied ? 'text-red-800 dark:text-red-300' :
                            ($isReserved ? 'text-yellow-800 dark:text-yellow-300' :
                            ($isCleaning ? 'text-blue-800 dark:text-blue-300' :
                            ($isMaintenance ? 'text-gray-800 dark:text-gray-300' :
                            'text-green-800 dark:text-green-300')))}}">
                            {{ $table->name }}
                        </h3>

                        {{-- Status Badge --}}
                        <span class="px-2 py-1 rounded text-xs font-bold uppercase tracking-wide {{
                            $isOccupied ? 'bg-red-200 dark:bg-red-800 text-red-800 dark:text-red-200' :
                            ($isReserved ? 'bg-yellow-200 dark:bg-yellow-800 text-yellow-800 dark:text-yellow-200' :
                            ($isCleaning ? 'bg-blue-200 dark:bg-blue-800 text-blue-800 dark:text-blue-200' :
                            ($isMaintenance ? 'bg-gray-200 dark:bg-gray-800 text-gray-800 dark:text-gray-200' :
                            'bg-green-200 dark:bg-green-800 text-green-800 dark:text-green-200')))}}">
                            {{ $table->status }}
                        </span>
                    </div>

                    {{-- Body: Details --}}
                    <div class="space-y-1 mb-3 md:mb-4">
                        @if($isOccupied && $activeOrder)
                            <div class="text-xl md:text-2xl font-bold text-gray-800 dark:text-gray-200">₦{{ number_format($total) }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Seated {{ $orderTime }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 font-medium">Order: #{{ $activeOrder->order_number }}</div>

                            {{-- Order Status Indicator --}}
                            <div class="mt-2">
                                @php
                                    $statusConfig = [
                                        'pending' => ['color' => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200', 'icon' => '📝', 'label' => 'Pending'],
                                        'preparing' => ['color' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300', 'icon' => '👨‍🍳', 'label' => 'Preparing'],
                                        'ready' => ['color' => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300', 'icon' => '✅', 'label' => 'Ready'],
                                        'served' => ['color' => 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300', 'icon' => '🍽️', 'label' => 'Served'],
                                    ];
                                    $statusInfo = $statusConfig[$orderStatus] ?? $statusConfig['pending'];
                                @endphp
                                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium {{ $statusInfo['color'] }}">
                                    {{ $statusInfo['icon'] }} {{ $statusInfo['label'] }}
                                </span>
                            </div>
                        @elseif($isReserved)
                            <div class="text-sm text-yellow-700 dark:text-yellow-400 italic">Reserved for Guest</div>
                        @elseif($isCleaning)
                            <div class="text-sm text-blue-700 dark:text-blue-400 flex items-center gap-1">
                                <x-heroicon-m-sparkles class="w-4 h-4"/> Being Cleaned
                            </div>
                        @elseif($isMaintenance)
                            <div class="text-sm text-gray-700 dark:text-gray-400 flex items-center gap-1">
                                <x-heroicon-m-wrench-screwdriver class="w-4 h-4"/> Under Maintenance
                            </div>
                        @else
                            <div class="text-sm text-green-700 dark:text-green-400 flex items-center gap-1">
                                <x-heroicon-m-check-circle class="w-4 h-4"/> Ready
                            </div>
                        @endif
                    </div>

                    {{-- Footer: Actions --}}
                    <div class="grid grid-cols-1 gap-2 mt-2">
                        {{-- 1. BUTTON: Go to POS (Opens in new tab with ID) --}}
                        @if($isAvailable)
                            <a href="/admin/pos-page?table_id={{ $table->id }}"
                            class="text-center bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 py-2 md:py-3 px-3 md:px-4 rounded-lg text-sm font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition shadow-sm">
                                ➕ New Order
                            </a>
                        @elseif($isOccupied && $activeOrder && $activeOrder->user_id === auth()->id())
                            <a href="/admin/table-detail?table_id={{ $table->id }}"
                            class="text-center bg-indigo-600 hover:bg-indigo-700 text-white py-2 md:py-3 px-3 md:px-4 rounded-lg text-sm font-bold transition shadow-sm">
                                ⚡ Manage
                            </a>
                        @elseif($isOccupied && $activeOrder)
                            <button disabled
                            class="text-center bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-500 dark:text-gray-400 py-2 md:py-3 px-3 md:px-4 rounded-lg text-sm font-bold cursor-not-allowed">
                                🔒 Managed by {{ $activeOrder->user->name ?? 'Another Waiter' }}
                            </button>
                        @else
                            <button disabled
                            class="text-center bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-500 dark:text-gray-400 py-2 md:py-3 px-3 md:px-4 rounded-lg text-sm font-bold cursor-not-allowed">
                                {{ $isCleaning ? '🧽 Cleaning' : ($isReserved ? '📅 Reserved' : '🔧 Maintenance') }}
                            </button>
                        @endif

                        {{-- 2. BUTTON: Print Receipt (visible for any occupied table with items) --}}
                        @if($isOccupied && $activeOrder)
                            <button
                                wire:click="openPrintModal({{ $table->id }})"
                                wire:loading.attr="disabled"
                                onclick="event.stopPropagation()"
                                class="text-center bg-amber-500 hover:bg-amber-600 active:bg-amber-700 text-white py-2 md:py-3 px-3 md:px-4 rounded-lg text-sm font-bold transition shadow-sm flex items-center justify-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                                </svg>
                                Print Receipt
                            </button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        {{-- ============================================================ --}}
        {{-- PRINT RECEIPT CONFIRMATION MODAL                            --}}
        {{-- ============================================================ --}}
        @if($showPrintModal)
            <div class="fixed inset-0 bg-black/60 z-[60] flex items-center justify-center p-4 backdrop-blur-sm"
                 wire:key="print-modal-{{ $printTableId }}">
                <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md overflow-hidden border border-gray-200 dark:border-gray-700">

                    {{-- Modal Header --}}
                    <div class="bg-amber-50 dark:bg-gray-800 p-4 border-b border-amber-200 dark:border-gray-700 flex justify-between items-center">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2">
                            <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                            </svg>
                            Print Receipt – {{ $printTableName }}
                        </h3>
                        <button wire:click="closePrintModal" class="text-gray-400 hover:text-red-500 touch-manipulation p-2">
                            <span class="text-2xl leading-none">&times;</span>
                        </button>
                    </div>

                    {{-- Modal Body: Items List --}}
                    <div class="p-5 max-h-72 overflow-y-auto">
                        @if(empty($printItems))
                            <p class="text-center text-gray-500 dark:text-gray-400 text-sm py-4">No items found for this table.</p>
                        @else
                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Review the order before printing:</p>
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 dark:border-gray-700">
                                        <th class="text-left py-1 pr-2 font-semibold text-gray-700 dark:text-gray-300">Item</th>
                                        <th class="text-center py-1 px-2 font-semibold text-gray-700 dark:text-gray-300">Qty</th>
                                        <th class="text-right py-1 pl-2 font-semibold text-gray-700 dark:text-gray-300">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($printItems as $item)
                                        <tr class="border-b border-gray-100 dark:border-gray-800">
                                            <td class="py-1.5 pr-2 text-gray-800 dark:text-gray-200">{{ $item['name'] }}</td>
                                            <td class="py-1.5 px-2 text-center text-gray-600 dark:text-gray-400">{{ $item['quantity'] }}</td>
                                            <td class="py-1.5 pl-2 text-right text-gray-800 dark:text-gray-200">₦{{ number_format($item['price'] * $item['quantity']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            <div class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-700 flex justify-end">
                                <span class="text-base font-bold text-gray-900 dark:text-white">TOTAL: ₦{{ number_format($printTotal) }}</span>
                            </div>
                        @endif
                    </div>

                    {{-- Modal Footer: Actions --}}
                    <div class="p-4 border-t border-gray-200 dark:border-gray-700 grid grid-cols-2 gap-3">
                        <button wire:click="closePrintModal"
                            class="px-4 py-3 font-bold text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 touch-manipulation transition">
                            Cancel
                        </button>
                        @if(!empty($printItems))
                            <button
                                x-on:click="
                                    $wire.call('closePrintModal');
                                    window.printPOSBill({
                                        tableName: '{{ addslashes($printTableName) }}',
                                        items: {{ json_encode($printItems) }},
                                        total: {{ $printTotal }},
                                        date: '{{ now()->format('M j, Y g:i A') }}',
                                        cashier: '{{ addslashes(auth()->user()->name) }}',
                                        company: {
                                            name: '{{ addslashes($printCompanyName) }}',
                                            address: '{{ addslashes($printCompanyAddress) }}',
                                            phone: '{{ addslashes($printCompanyPhone) }}',
                                            logo: '{{ $printCompanyLogo }}'
                                        }
                                    });
                                "
                                class="px-4 py-3 font-bold text-white bg-amber-500 rounded-lg hover:bg-amber-600 active:bg-amber-700 touch-manipulation transition flex items-center justify-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                                </svg>
                                Confirm & Print
                            </button>
                        @else
                            <button disabled
                                class="px-4 py-3 font-bold text-white bg-gray-400 rounded-lg cursor-not-allowed">
                                No Items to Print
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        @endif


    </div>
</x-filament-panels::page>