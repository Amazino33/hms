<div class="space-y-4">
    <div class="overflow-hidden border border-gray-200 dark:border-gray-700 rounded-lg">
        <table class="w-full text-sm text-left">
            <thead class="bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-200 font-bold">
                <tr>
                    <th class="px-4 py-2">Date</th>
                    <th class="px-4 py-2">Staff</th>
                    <th class="px-4 py-2">Method</th>
                    <th class="px-4 py-2 text-right">Amount</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse($payments as $payment)
                    <tr class="bg-white dark:bg-gray-900 hover:bg-gray-50 dark:hover:bg-gray-800">
                        <td class="px-4 py-2 text-gray-500">
                            {{ $payment->paid_at ? \Carbon\Carbon::parse($payment->paid_at)->format('d M, h:i A') : 'N/A' }}
                        </td>
                        <td class="px-4 py-2 font-medium">
                            {{ $payment->user->name ?? 'Unknown' }}
                        </td>
                        <td class="px-4 py-2 uppercase text-xs font-bold text-gray-500">
                            {{ $payment->method }}
                        </td>
                        <td class="px-4 py-2 text-right font-bold text-green-600">
                            ₦{{ number_format($payment->amount) }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-4 text-center text-gray-400">
                            No split payments recorded.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>