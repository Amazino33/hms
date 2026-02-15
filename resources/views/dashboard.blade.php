<x-layouts::app :title="__('Dashboard')">
    <!-- Desktop Dashboard (Hidden on Mobile) - deferred load -->
    <div class="hidden lg:block">
        <div class="p-6">
            <livewire:dashboard-stats />
        </div>
    </div>

    <!-- Mobile Dashboard (Hidden on Desktop) -->
    <div class="lg:hidden min-h-screen bg-gray-50 dark:bg-gray-900">
        <!-- Mobile Header -->
        <div class="bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 px-4 py-3 sticky top-0 z-40">
            <div class="flex items-center justify-between">
                <h1 class="text-lg font-bold text-gray-900 dark:text-white">📊 Dashboard</h1>
                <div class="flex items-center space-x-2">
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        {{ now()->format('M j, Y') }}
                    </div>
                </div>
            </div>
        </div>

        <!-- Mobile dashboard (deferred load) -->
        <livewire:dashboard-stats />

        <!-- Mobile Bottom Navigation -->

        <!-- Mobile Bottom Navigation -->
        <div class="fixed bottom-0 left-0 right-0 bg-white dark:bg-gray-900 border-t border-gray-200 dark:border-gray-700 px-4 py-2">
            <div class="flex justify-around items-center">
                <a href="{{ route('dashboard') }}" wire:navigate
                    class="flex flex-col items-center p-2 text-blue-600 dark:text-blue-400">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5a2 2 0 012-2h4a2 2 0 012 2v2H8V5z"></path>
                    </svg>
                    <span class="text-xs mt-1">Dashboard</span>
                </a>

                <a href="{{ route('pos.index') }}" wire:navigate
                    class="flex flex-col items-center p-2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                    </svg>
                    <span class="text-xs mt-1">POS</span>
                </a>

                <a href="#" wire:navigate
                    class="flex flex-col items-center p-2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                    </svg>
                    <span class="text-xs mt-1">Reports</span>
                </a>

                <a href="{{ route('profile.edit') }}" wire:navigate
                    class="flex flex-col items-center p-2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                    <span class="text-xs mt-1">Settings</span>
                </a>
            </div>
        </div>

        <!-- Add bottom padding for fixed navigation -->
        <div class="pb-16"></div>
    </div>
</x-layouts::app>
