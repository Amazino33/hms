<div>
    @if($showInstallButton && !$isInstalled)
        <div class="fixed bottom-4 left-4 right-4 md:left-auto md:right-4 md:w-80 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg shadow-lg p-4 z-50">
            <div class="flex items-start space-x-3">
                <div class="flex-shrink-0">
                    <svg class="w-8 h-8 text-blue-600" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M3 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"></path>
                    </svg>
                </div>
                <div class="flex-1">
                    <h3 class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
                        Install HMS App
                    </h3>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">
                        Get the full experience with offline access and native app features.
                    </p>
                    <div class="mt-3 flex space-x-2">
                        <button
                            wire:click="installApp"
                            class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-2 rounded-md transition-colors"
                        >
                            Install App
                        </button>
                        <button
                            wire:click="$set('showInstallButton', false)"
                            class="text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200 text-sm font-medium px-4 py-2"
                        >
                            Not now
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <script>
            document.addEventListener('livewire:loaded', () => {
                // Listen for PWA installable event
                window.addEventListener('pwa-installable', () => {
                    @this.set('showInstallButton', true);
                });

                // Listen for install action
                Livewire.on('install-pwa', () => {
                    if (window.installPWA) {
                        window.installPWA();
                        @this.set('showInstallButton', false);
                    }
                });

                // Check if already installed
                if (window.matchMedia('(display-mode: standalone)').matches) {
                    @this.set('isInstalled', true);
                }
            });
        </script>
    @endif
</div>