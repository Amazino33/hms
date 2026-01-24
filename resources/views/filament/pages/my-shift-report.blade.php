<x-filament-panels::page>

    <div class="max-w-xl mx-auto bg-white dark:bg-gray-900 shadow-lg rounded-xl overflow-hidden border border-gray-200 dark:border-gray-700">
        
        <div class="bg-gray-50 dark:bg-gray-800 p-6 text-center border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-2xl font-black text-gray-800 dark:text-white uppercase tracking-wider">
                SHIFT SUMMARY
            </h2>
            <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                {{ auth()->user()->name }} • {{ $date }}
            </div>
        </div>

        <div class="p-6 grid grid-cols-2 gap-4 text-center">
            
            <div class="p-4 bg-green-50 dark:bg-green-900/20 rounded-xl border border-green-200 dark:border-green-800">
                <div class="text-xs font-bold text-green-600 dark:text-green-400 uppercase">Cash to Remit</div>
                <div class="text-3xl font-black text-green-700 dark:text-green-300 mt-1">
                    ₦{{ number_format($cash_hand) }}
                </div>
            </div>

            <div class="p-4 bg-blue-50 dark:bg-blue-900/20 rounded-xl border border-blue-200 dark:border-blue-800">
                <div class="text-xs font-bold text-blue-600 dark:text-blue-400 uppercase">POS / Transfer</div>
                <div class="text-3xl font-black text-blue-700 dark:text-blue-300 mt-1">
                    ₦{{ number_format($pos_total) }}
                </div>
            </div>
        </div>

        <div class="bg-gray-800 text-white p-4 flex justify-between items-center px-8">
            <span class="font-medium text-gray-300">Total Shift Value:</span>
            <span class="text-xl font-bold">₦{{ number_format($total_collected) }}</span>
        </div>

        <div class="p-6 border-t border-gray-200 dark:border-gray-700">
            <h3 class="font-bold text-sm text-gray-500 uppercase mb-3">Transaction Log</h3>
            
            <div class="max-h-64 overflow-y-auto space-y-2 pr-2">
                @forelse($transactions as $t)
                    <div class="flex justify-between items-center text-sm p-2 hover:bg-gray-50 dark:hover:bg-gray-800 rounded-lg border border-transparent hover:border-gray-100 dark:hover:border-gray-700">
                        <div>
                            <div class="font-bold text-gray-800 dark:text-gray-200">
                                #{{ $t->order->order_number ?? 'Order' }}
                            </div>
                            <div class="text-xs text-gray-400">
                                {{ $t->paid_at->format('h:i A') }} • {{ strtoupper($t->method) }}
                            </div>
                        </div>
                        <div class="font-mono font-bold {{ $t->method === 'cash' ? 'text-green-600' : 'text-blue-600' }}">
                            ₦{{ number_format($t->amount) }}
                        </div>
                    </div>
                @empty
                    <div class="text-center text-gray-400 italic py-4">No transactions yet today.</div>
                @endforelse
            </div>
        </div>

        <div class="p-6 bg-gray-50 dark:bg-gray-800 text-center border-t border-gray-200 dark:border-gray-700">
            <p class="text-xs text-gray-400 mb-4">By closing, I confirm these amounts are correct.</p>
            <button onclick="window.print()" class="w-full py-3 bg-black text-white rounded-xl font-bold hover:bg-gray-800 transition-colors shadow-lg">
                🖨️ Print / Save Report
            </button>
        </div>

    </div>

</x-filament-panels::page>