<x-filament-widgets::widget>
    <div x-data="{
        deferredPrompt: null,
        init() {
            window.addEventListener('beforeinstallprompt', (e) => {
                e.preventDefault();
                this.deferredPrompt = e;
            });
            window.addEventListener('appinstalled', () => {
                this.deferredPrompt = null;
            });
        },
        async installApp() {
            if (!this.deferredPrompt) return;
            this.deferredPrompt.prompt();
            const { outcome } = await this.deferredPrompt.userChoice;
            if (outcome === 'accepted') {
                this.deferredPrompt = null;
            }
        }
    }" x-show="deferredPrompt" x-cloak>
        <x-filament::section class="bg-primary-50 dark:bg-primary-900/20 border-primary-500/30">
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div class="p-3 bg-primary-500 rounded-lg text-white">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white">Install HMS POS</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Install this application on your device for a faster, app-like experience.</p>
                    </div>
                </div>

                <x-filament::button @click="installApp()" color="primary" size="lg">
                    Install App
                </x-filament::button>
            </div>
        </x-filament::section>
    </div>
</x-filament-widgets::widget>