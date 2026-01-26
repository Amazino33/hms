<!-- Desktop Settings Layout (Hidden on Mobile) -->
<div class="hidden lg:block">
    <div class="flex items-start max-md:flex-col">
        <div class="me-10 w-full pb-4 md:w-[220px]">
            <flux:navlist aria-label="{{ __('Settings') }}">
                <flux:navlist.item :href="route('profile.edit')" wire:navigate>{{ __('Profile') }}</flux:navlist.item>
                <flux:navlist.item :href="route('user-password.edit')" wire:navigate>{{ __('Password') }}</flux:navlist.item>
                @if (Laravel\Fortify\Features::canManageTwoFactorAuthentication())
                    <flux:navlist.item :href="route('two-factor.show')" wire:navigate>{{ __('Two-Factor Auth') }}</flux:navlist.item>
                @endif
                <flux:navlist.item :href="route('appearance.edit')" wire:navigate>{{ __('Appearance') }}</flux:navlist.item>
            </flux:navlist>
        </div>

        <flux:separator class="md:hidden" />

        <div class="flex-1 self-stretch max-md:pt-6">
            <flux:heading>{{ $heading ?? '' }}</flux:heading>
            <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

            <div class="mt-5 w-full max-w-lg">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>

<!-- Mobile Settings Layout (Hidden on Desktop) -->
<div class="lg:hidden min-h-screen bg-gray-50 dark:bg-gray-900">
    <!-- Mobile Header -->
    <div class="bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 px-4 py-3 sticky top-0 z-40">
        <div class="flex items-center justify-between">
            <h1 class="text-lg font-bold text-gray-900 dark:text-white">⚙️ Settings</h1>
            <a href="{{ route('dashboard') }}" wire:navigate class="text-gray-400 hover:text-gray-600 p-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </a>
        </div>
    </div>

    <!-- Mobile Settings Navigation -->
    <div class="bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700">
        <div class="px-4 py-3">
            <div class="grid grid-cols-2 gap-3">
                <a href="{{ route('profile.edit') }}" wire:navigate
                    class="flex items-center space-x-3 p-3 rounded-lg border {{ request()->routeIs('profile.edit') ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800' }} transition-colors touch-manipulation">
                    <div class="w-8 h-8 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                        <svg class="w-4 h-4 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                        </svg>
                    </div>
                    <div>
                        <div class="text-sm font-medium text-gray-900 dark:text-white">Profile</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Update your info</div>
                    </div>
                </a>

                <a href="{{ route('user-password.edit') }}" wire:navigate
                    class="flex items-center space-x-3 p-3 rounded-lg border {{ request()->routeIs('user-password.edit') ? 'border-green-500 bg-green-50 dark:bg-green-900/20' : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800' }} transition-colors touch-manipulation">
                    <div class="w-8 h-8 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                        <svg class="w-4 h-4 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                        </svg>
                    </div>
                    <div>
                        <div class="text-sm font-medium text-gray-900 dark:text-white">Password</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Change password</div>
                    </div>
                </a>

                @if (Laravel\Fortify\Features::canManageTwoFactorAuthentication())
                    <a href="{{ route('two-factor.show') }}" wire:navigate
                        class="flex items-center space-x-3 p-3 rounded-lg border {{ request()->routeIs('two-factor.show') ? 'border-purple-500 bg-purple-50 dark:bg-purple-900/20' : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800' }} transition-colors touch-manipulation">
                        <div class="w-8 h-8 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                            <svg class="w-4 h-4 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                            </svg>
                        </div>
                        <div>
                            <div class="text-sm font-medium text-gray-900 dark:text-white">2FA</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Two-factor auth</div>
                        </div>
                    </a>
                @endif

                <a href="{{ route('appearance.edit') }}" wire:navigate
                    class="flex items-center space-x-3 p-3 rounded-lg border {{ request()->routeIs('appearance.edit') ? 'border-orange-500 bg-orange-50 dark:bg-orange-900/20' : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800' }} transition-colors touch-manipulation">
                    <div class="w-8 h-8 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center">
                        <svg class="w-4 h-4 text-orange-600 dark:text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zM21 5a2 2 0 00-2-2h-4a2 2 0 00-2 2v12a4 4 0 004 4h4a2 2 0 002-2V5z"></path>
                        </svg>
                    </div>
                    <div>
                        <div class="text-sm font-medium text-gray-900 dark:text-white">Appearance</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Theme & display</div>
                    </div>
                </a>
            </div>
        </div>
    </div>

    <!-- Mobile Settings Content -->
    <div class="flex-1 p-4">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-6">
            <flux:heading class="mb-2">{{ $heading ?? '' }}</flux:heading>
            <flux:subheading class="mb-6 text-gray-600 dark:text-gray-400">{{ $subheading ?? '' }}</flux:subheading>

            <div class="w-full">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
