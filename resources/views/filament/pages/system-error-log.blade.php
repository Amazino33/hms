<div class="min-h-screen p-6 bg-gradient-to-br from-gray-50 to-gray-100 dark:from-gray-900 dark:to-gray-950" wire:poll.15s>
    <div class="max-w-5xl mx-auto mb-6 flex items-center justify-between">
        <div>
            <h2 class="text-2xl font-bold text-gray-900 dark:text-white">System Error Log</h2>
            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                Every error notification shown to a user, plus every unexpected system error (with full detail) — read straight from the server's log file, so it still shows up even if the database itself is the problem. Refreshes automatically.
            </p>
        </div>
        <button type="button" wire:click="clearLog" wire:confirm="Clear the whole error log? This cannot be undone."
            class="px-4 py-2 rounded-lg bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200 text-sm font-bold touch-manipulation">
            Clear log
        </button>
    </div>

    <div class="max-w-5xl mx-auto space-y-3">
        @forelse ($entries as $i => $entry)
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                <button type="button" wire:click="toggleExpand({{ $i }})" class="w-full text-left p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                @if (($entry['source'] ?? 'exception') === 'notification')
                                    <span class="px-2 py-0.5 rounded-md bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 text-xs font-bold">
                                        Notification shown to user
                                    </span>
                                @else
                                    <span class="px-2 py-0.5 rounded-md bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 text-xs font-bold">
                                        {{ class_basename($entry['class'] ?? 'Unknown') }}
                                    </span>
                                @endif
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $entry['time'] ?? '—' }}</span>
                                @if (!empty($entry['url']))
                                    <span class="text-xs text-gray-400 dark:text-gray-500 truncate">{{ $entry['method'] ?? '' }} {{ $entry['url'] }}</span>
                                @endif
                            </div>
                            <p class="text-sm text-gray-900 dark:text-gray-100 font-semibold mt-1 truncate">{{ $entry['message'] ?? '(no message)' }}</p>
                            @if (!empty($entry['body']))
                                <p class="text-xs text-gray-600 dark:text-gray-400 mt-0.5 truncate">{{ $entry['body'] }}</p>
                            @endif
                        </div>
                        <svg class="w-5 h-5 text-gray-400 shrink-0 transition-transform {{ $expandedIndex === $i ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                </button>

                @if ($expandedIndex === $i)
                    <div class="px-4 pb-4 border-t border-gray-100 dark:border-gray-700 pt-3">
                        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1 text-xs mb-3">
                            @if (($entry['source'] ?? 'exception') === 'exception')
                                <div><dt class="inline font-semibold text-gray-500 dark:text-gray-400">File:</dt> <dd class="inline text-gray-700 dark:text-gray-300">{{ $entry['file'] ?? '—' }}:{{ $entry['line'] ?? '—' }}</dd></div>
                                <div><dt class="inline font-semibold text-gray-500 dark:text-gray-400">Code:</dt> <dd class="inline text-gray-700 dark:text-gray-300">{{ $entry['code'] ?? '—' }}</dd></div>
                            @endif
                            <div><dt class="inline font-semibold text-gray-500 dark:text-gray-400">User ID:</dt> <dd class="inline text-gray-700 dark:text-gray-300">{{ $entry['user_id'] ?? 'not logged in' }}</dd></div>
                        </dl>
                        @if (!empty($entry['trace']))
                            <pre class="text-xs bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg p-3 overflow-x-auto whitespace-pre-wrap text-gray-700 dark:text-gray-300">{{ $entry['trace'] }}</pre>
                        @endif
                    </div>
                @endif
            </div>
        @empty
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-8 text-center text-sm text-gray-500 dark:text-gray-400">
                No errors recorded. That's a good thing.
            </div>
        @endforelse
    </div>
</div>
