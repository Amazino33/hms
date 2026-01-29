<div>
    @if($installSuccess)
        <div class="fixed bottom-4 left-4 right-4 md:left-auto md:right-4 md:w-80 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg shadow-lg p-4 z-50">
            <div class="flex items-start space-x-3">
                <div class="flex-shrink-0">
                    <svg class="w-8 h-8 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                    </svg>
                </div>
                <div class="flex-1">
                    <h3 class="text-sm font-medium text-green-900 dark:text-green-100">
                        App Installed Successfully!
                    </h3>
                    <p class="text-sm text-green-700 dark:text-green-300 mt-1">
                        You can now access HMS from your home screen.
                    </p>
                </div>
            </div>
        </div>
    @elseif($installError)
        <div class="fixed bottom-4 left-4 right-4 md:left-auto md:right-4 md:w-80 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg shadow-lg p-4 z-50">
            <div class="flex items-start space-x-3">
                <div class="flex-shrink-0">
                    <svg class="w-8 h-8 text-red-600" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                    </svg>
                </div>
                <div class="flex-1">
                    <h3 class="text-sm font-medium text-red-900 dark:text-red-100">
                        Installation Failed
                    </h3>
                    <p class="text-sm text-red-700 dark:text-red-300 mt-1">
                        {{ $errorMessage }}
                    </p>
                    <div class="mt-3">
                        <button
                            wire:click="installApp"
                            class="bg-red-600 hover:bg-red-700 text-white text-sm font-medium px-4 py-2 rounded-md transition-colors"
                        >
                            Try Again
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @elseif($showInstallButton && !$isInstalled)
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
                            :disabled="$wire.installing"
                            class="bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white text-sm font-medium px-4 py-2 rounded-md transition-colors flex items-center space-x-2"
                        >
                            <span x-show="!$wire.installing">Install App</span>
                            <span x-show="$wire.installing" class="flex items-center space-x-2">
                                <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span>Installing...</span>
                            </span>
                        </button>
                        <button
                            wire:click="dismissPrompt"
                            class="text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200 text-sm font-medium px-4 py-2"
                        >
                            Not now
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <script>
        document.addEventListener('livewire:loaded', () => {
            // Listen for PWA installable event
            window.addEventListener('pwa-installable', () => {
                @this.set('showInstallButton', true);
            });

            // Listen for install action
            Livewire.on('install-pwa', () => {
                if (window.installPWA) {
                    window.installPWA()
                        .then(() => {
                            @this.call('markAsInstalled');
                        })
                        .catch((error) => {
                            console.error('PWA installation failed:', error);
                            @this.call('markInstallError', error.message || 'Installation failed. Please try again.');
                        });
                } else {
                    @this.call('markInstallError', 'Installation not available. Please refresh the page and try again.');
                }
            });

            // Hide success message after 3 seconds
            Livewire.on('hide-success-message', () => {
                setTimeout(() => {
                    @this.set('installSuccess', false);
                }, 3000);
            });

            // Hide error message after 5 seconds
            Livewire.on('hide-error-message', () => {
                setTimeout(() => {
                    @this.set('installError', false);
                    @this.set('errorMessage', '');
                }, 5000);
            });

            // Check if already installed
            if (window.matchMedia('(display-mode: standalone)').matches) {
                @this.set('isInstalled', true);
            }

            // Check for iOS Safari specific behavior
            const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);
            const isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);

            if (isIOS && isSafari) {
                // iOS Safari requires manual install prompt
                const installBanner = document.createElement('div');
                installBanner.className = 'fixed top-0 left-0 right-0 bg-blue-600 text-white p-3 text-center text-sm z-50';
                installBanner.innerHTML = `
                    <div class="flex items-center justify-between">
                        <span>Tap <svg class="inline w-4 h-4 ml-1" fill="currentColor" viewBox="0 0 20 20"><path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.409l-7-14z"></path></svg> and "Add to Home Screen"</span>
                        <button onclick="this.parentElement.parentElement.remove()" class="text-white hover:text-blue-200">×</button>
                    </div>
                `;
                document.body.appendChild(installBanner);

                // Auto-hide after 10 seconds
                setTimeout(() => {
                    if (installBanner.parentElement) {
                        installBanner.remove();
                    }
                }, 10000);
            }
        });
    </script>
</div>