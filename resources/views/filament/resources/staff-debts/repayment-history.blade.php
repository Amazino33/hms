<div class="space-y-3">
    <div class="flex justify-between text-sm font-bold border-b border-gray-200 dark:border-gray-700 pb-2">
        <span>Original amount</span>
        <span>₦{{ number_format((float) $debt->amount, 2) }}</span>
    </div>

    @forelse ($debt->repayments as $repayment)
        <div class="flex justify-between items-center text-sm">
            <div>
                <div class="text-gray-800 dark:text-gray-200">{{ ucfirst(str_replace('_', ' ', $repayment->method)) }}</div>
                <div class="text-xs text-gray-400">{{ $repayment->created_at->format('d M Y H:i') }} — recorded by {{ $repayment->recordedBy->name ?? '—' }}</div>
                @if ($repayment->notes)
                    <div class="text-xs text-gray-400 italic">{{ $repayment->notes }}</div>
                @endif
            </div>
            <div class="font-mono text-green-600 dark:text-green-400">−₦{{ number_format((float) $repayment->amount, 2) }}</div>
        </div>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">No repayments recorded yet.</p>
    @endforelse

    <div class="flex justify-between text-sm font-bold border-t border-gray-200 dark:border-gray-700 pt-2">
        <span>Outstanding balance</span>
        <span>₦{{ number_format($debt->remainingBalance(), 2) }}</span>
    </div>
</div>
