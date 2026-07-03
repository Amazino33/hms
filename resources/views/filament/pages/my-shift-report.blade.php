<x-filament-panels::page>

    <div class="max-w-xl mx-auto bg-white dark:bg-gray-900 shadow-lg rounded-xl overflow-hidden border border-gray-200 dark:border-gray-700">
        
        <div class="bg-gray-50 dark:bg-gray-800 p-6 text-center border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-2xl font-black text-gray-800 dark:text-white uppercase tracking-wider">
                SHIFT SUMMARY
            </h2>
            <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                {{ auth()->user()->name }} • 
                @if($shift_active)
                    Shift Started: {{ $shift_start }} ({{ $shift_duration }})
                @else
                    No Active Shift
                @endif
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

            <div class="p-4 bg-red-50 dark:bg-red-900/20 rounded-xl border border-red-200 dark:border-red-800">
                <div class="text-xs font-bold text-red-600 dark:text-red-400 uppercase">Outstanding Debt</div>
                <div class="text-3xl font-black text-red-700 dark:text-red-300 mt-1">
                    ₦{{ number_format($total_debt) }}
                </div>
            </div>

            <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
                <div class="text-xs font-bold text-gray-600 dark:text-gray-400 uppercase">Net Position</div>
                <div class="text-3xl font-black text-gray-700 dark:text-gray-300 mt-1">
                    ₦{{ number_format($total_collected - $total_debt) }}
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
                    <div class="text-center text-gray-400 italic py-4">
                        @if($shift_active)
                            No transactions yet this shift.
                        @else
                            No active shift to display transactions.
                        @endif
                    </div>
                @endforelse
            </div>

            @if($partial_orders->count() > 0)
            <h3 class="font-bold text-sm text-gray-500 uppercase mb-3 mt-6">Unpaid Orders — Must Resolve Before Ending Shift</h3>

            <div class="max-h-64 overflow-y-auto space-y-2 pr-2">
                @foreach($partial_orders as $order)
                    <div class="flex justify-between items-center text-sm p-2 hover:bg-gray-50 dark:hover:bg-gray-800 rounded-lg border border-transparent hover:border-gray-100 dark:hover:border-gray-700">
                        <div>
                            <div class="font-bold text-gray-800 dark:text-gray-200">
                                #{{ $order->order_number }}
                            </div>
                            <div class="text-xs text-gray-400">
                                {{ $order->created_at->format('h:i A') }} • {{ $order->guest ? $order->guest->name : 'Walk-in' }} • {{ ucfirst($order->status) }}
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="font-mono font-bold text-red-600">
                                ₦{{ number_format($order->total_amount - $order->amount_paid) }}
                            </div>
                            @if($can_convert_debt ?? false)
                                <button wire:click="convertToDebt({{ $order->id }})"
                                    onclick="return confirm('Convert this order\'s unpaid balance into a staff debt?')"
                                    class="text-xs px-2 py-1 bg-amber-600 hover:bg-amber-700 text-white rounded font-medium">
                                    Convert to Debt
                                </button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
            @endif

            @if(($my_debts ?? collect())->count() > 0)
            <h3 class="font-bold text-sm text-gray-500 uppercase mb-3 mt-6">My Open Debts</h3>

            <div class="max-h-64 overflow-y-auto space-y-2 pr-2">
                @foreach($my_debts as $debt)
                    <div class="flex justify-between items-center text-sm p-2 rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20">
                        <div>
                            <div class="font-bold text-gray-800 dark:text-gray-200">
                                {{ ucfirst(str_replace('_', ' ', $debt->reason)) }}
                            </div>
                            <div class="text-xs text-gray-400">
                                {{ $debt->created_at->format('M j, Y') }} • {{ ucfirst(str_replace('_', ' ', $debt->status)) }}
                            </div>
                        </div>
                        <div class="font-mono font-bold text-amber-700 dark:text-amber-400">
                            ₦{{ number_format($debt->remainingBalance()) }}
                        </div>
                    </div>
                @endforeach
                <div class="flex justify-between items-center text-sm p-2 font-bold border-t border-gray-200 dark:border-gray-700 pt-2">
                    <span>Total Owed</span>
                    <span class="text-amber-700 dark:text-amber-400">₦{{ number_format($my_debts_total ?? 0) }}</span>
                </div>
            </div>
            @endif
        </div>

        @if($shift_history->count() > 0)
        <div class="p-6 border-t border-gray-200 dark:border-gray-700">
            <h3 class="font-bold text-sm text-gray-500 uppercase mb-3">Today's Shift History</h3>
            
            <div class="space-y-3">
                @foreach($shift_history as $shift)
                    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
                        <div class="flex justify-between items-start mb-2">
                            <div>
                                <div class="font-bold text-gray-800 dark:text-gray-200">
                                    Shift #{{ $shift['id'] }}
                                </div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $shift['started_at']->format('M j, g:i A') }} - {{ $shift['ended_at']->format('g:i A') }} 
                                    ({{ $shift['duration'] }} minutes)
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="font-mono font-bold text-green-600 dark:text-green-400">
                                    ₦{{ number_format($shift['total_payments']) }}
                                </div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $shift['transaction_count'] }} transactions
                                </div>
                            </div>
                        </div>
                        <div class="flex justify-between text-xs text-gray-600 dark:text-gray-400">
                            <span>Cash: ₦{{ number_format($shift['cash_payments']) }}</span>
                            <span>POS/Transfer: ₦{{ number_format($shift['pos_payments']) }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
        @endif

        <div class="p-6 bg-gray-50 dark:bg-gray-800 text-center border-t border-gray-200 dark:border-gray-700">
            @if($shift_active)
                <p class="text-xs text-gray-400 mb-4">By closing, I confirm these amounts are correct for this shift.</p>
                <button onclick="window.print()" class="w-full py-3 bg-black text-white rounded-xl font-bold hover:bg-gray-800 transition-colors shadow-lg">
                    🖨️ Print / Save Shift Report
                </button>
            @else
                <p class="text-xs text-gray-400 mb-4">Start a shift to begin tracking your transactions.</p>
                <button disabled class="w-full py-3 bg-gray-300 text-gray-500 rounded-xl font-bold cursor-not-allowed">
                    No Active Shift
                </button>
            @endif
        </div>

    </div>

</x-filament-panels::page>