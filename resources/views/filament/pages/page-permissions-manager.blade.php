<x-filament-panels::page>
    <div class="space-y-6">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900/30 rounded-lg flex items-center justify-center">
                    <x-heroicon-o-shield-check class="w-6 h-6 text-blue-600 dark:text-blue-400"/>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 dark:text-white">Page Permissions Manager</h1>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Control which roles can access specific pages and dashboard widgets</p>
                </div>
            </div>

            <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-lg p-4 mb-6">
                <div class="flex items-start gap-3">
                    <x-heroicon-o-exclamation-triangle class="w-5 h-5 text-amber-600 dark:text-amber-400 mt-0.5"/>
                    <div>
                        <h3 class="font-medium text-amber-800 dark:text-amber-200">Important Notes</h3>
                        <ul class="mt-2 text-sm text-amber-700 dark:text-amber-300 space-y-1">
                            <li>• Super Admin always has access to all pages and widgets</li>
                            <li>• If no roles are selected for a page or widget, access is denied to everyone except Super Admin</li>
                            <li>• Changes take effect immediately after saving</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <form wire:submit.prevent="savePermissions" class="space-y-6">
            @foreach($permissions as $pageClass => $pageData)
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                            {{ $pageData['name'] }}
                        </h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                            {{ $pageClass }}
                        </p>
                    </div>

                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            @php
                                $allRoles = \Spatie\Permission\Models\Role::all();
                            @endphp

                            @foreach($allRoles as $role)
                                <label class="flex items-center space-x-3 p-3 rounded-lg border border-gray-200 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700/50 cursor-pointer transition-colors">
                                    <input
                                        type="checkbox"
                                        wire:model="permissions.{{ $pageClass }}.roles"
                                        value="{{ $role->name }}"
                                        class="rounded border-gray-300 dark:border-gray-600 text-blue-600 focus:ring-blue-500 dark:bg-gray-700"
                                    >
                                    <div>
                                        <div class="font-medium text-gray-900 dark:text-white">{{ ucfirst($role->name) }}</div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">Role: {{ $role->name }}</div>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforeach

            {{-- ── Widget Visibility ─────────────────────────────────────── --}}
            @if(!empty($widgetPermissions))
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-indigo-200 dark:border-indigo-700 overflow-hidden">
                    <div class="px-6 py-4 bg-indigo-50 dark:bg-indigo-900/20 border-b border-indigo-200 dark:border-indigo-700 flex items-center gap-2">
                        <x-heroicon-o-squares-2x2 class="w-5 h-5 text-indigo-600 dark:text-indigo-400"/>
                        <h2 class="text-base font-bold text-indigo-900 dark:text-indigo-200">Widget Visibility</h2>
                        <span class="text-xs text-indigo-600 dark:text-indigo-400 ml-1">(Dashboard widgets that support role-based visibility)</span>
                    </div>
                </div>

                @foreach($widgetPermissions as $widgetClass => $widgetData)
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                                {{ $widgetData['name'] }}
                            </h3>
                            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                                {{ $widgetClass }}
                            </p>
                        </div>

                        <div class="p-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                @php $allRoles = \Spatie\Permission\Models\Role::all(); @endphp

                                @foreach($allRoles as $role)
                                    <label class="flex items-center space-x-3 p-3 rounded-lg border border-gray-200 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700/50 cursor-pointer transition-colors">
                                        <input
                                            type="checkbox"
                                            wire:model="widgetPermissions.{{ $widgetClass }}.roles"
                                            value="{{ $role->name }}"
                                            class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500 dark:bg-gray-700"
                                        >
                                        <div>
                                            <div class="font-medium text-gray-900 dark:text-white">{{ ucfirst($role->name) }}</div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400">Role: {{ $role->name }}</div>
                                        </div>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endforeach
            @endif

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                <div class="flex justify-end">
                    <button
                        type="submit"
                        class="inline-flex items-center px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg shadow-sm transition-colors"
                    >
                        <x-heroicon-o-check class="w-5 h-5 mr-2"/>
                        Save All Permissions
                    </button>
                </div>
            </div>
        </form>
    </div>
</x-filament-panels::page>