<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use App\Models\PagePermission;

class PermissionService
{
    /**
     * Check if user can manage ingredients
     */
    public static function canManageIngredients(): bool
    {
        $user = Auth::user();

        if (!$user) {
            return false;
        }

        // Check roles
        if ($user->hasRole(['super_admin', 'chef'])) {
            return true;
        }

        // Check specific permission
        if ($user->hasPermissionTo('manage_ingredients')) {
            return true;
        }

        // Check department-based access (optional)
        if ($user->departments && $user->departments->contains('name', 'kitchen')) {
            return true;
        }

        return false;
    }

    /**
     * Check if user can manage menu items
     */
    public static function canManageMenuItems(): bool
    {
        $user = Auth::user();

        if (!$user) {
            return false;
        }

        // Check roles
        if ($user->hasRole(['super_admin', 'chef', 'manager'])) {
            return true;
        }

        // Check specific permission
        if ($user->hasPermissionTo('manage_menu_items')) {
            return true;
        }

        return false;
    }

    /**
     * Check if user can manage orders
     */
    public static function canManageOrders(): bool
    {
        $user = Auth::user();

        if (!$user) {
            return false;
        }

        // Check roles
        if ($user->hasRole(['super_admin', 'manager', 'waiter'])) {
            return true;
        }

        // Check specific permission
        if ($user->hasPermissionTo('manage_orders')) {
            return true;
        }

        return false;
    }

    /**
     * Check if user can view reports
     */
    public static function canViewReports(): bool
    {
        $user = Auth::user();

        if (!$user) {
            return false;
        }

        // Check roles
        if ($user->hasRole(['super_admin', 'manager'])) {
            return true;
        }

        // Check specific permission
        if ($user->hasPermissionTo('view_reports')) {
            return true;
        }

        return false;
    }

    /**
     * Generic permission checker
     */
    public static function hasPermission(string $permission): bool
    {
        $user = Auth::user();

        if (!$user) {
            return false;
        }

        return $user->hasPermissionTo($permission);
    }

    /**
     * Check if user can access POS page
     */
    public static function canAccessPos(): bool
    {
        $user = Auth::user();

        if (!$user) {
            return false;
        }

        return $user->hasRole(['super_admin', 'waiter']);
    }

    /**
     * Check if user can access floor plan
     */
    public static function canAccessFloorPlan(): bool
    {
        $user = Auth::user();

        if (!$user) {
            return false;
        }

        return $user->hasRole(['super_admin', 'manager', 'waiter']);
    }

    /**
     * Check if user can access kitchen display
     */
    public static function canAccessKitchenDisplay(): bool
    {
        $user = Auth::user();

        if (!$user) {
            return false;
        }

        return $user->hasRole(['super_admin', 'chef']);
    }

    /**
     * Check if user can access bar display
     */
    public static function canAccessBarDisplay(): bool
    {
        $user = Auth::user();

        if (!$user) {
            return false;
        }

        return $user->hasRole(['super_admin', 'bartender']);
    }

    /**
     * Dynamic page access check based on database configuration
     */
    public static function canAccessPage(string $pageClass): bool
    {
        $user = Auth::user();

        if (!$user) {
            return false;
        }

        // Super admin always has access
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Check if any of user's roles have access to this page
        $userRoles = $user->roles->pluck('name')->toArray();

        foreach ($userRoles as $role) {
            if (PagePermission::roleHasAccess($pageClass, $role)) {
                return true;
            }
        }

        // Deny access if no permissions are configured (secure by default)
        return false;
    }

    /**
     * Get all available widgets that support dynamic visibility via canView().
     * Only widgets that implement canView() backed by this permission system
     * need an entry here — extend the list as you add more.
     */
    public static function getAvailableWidgets(): array
    {
        $widgets = [];

        $widgetFiles = glob(app_path('Filament/Widgets/*.php'));
        foreach ($widgetFiles as $file) {
            $className = 'App\\Filament\\Widgets\\' . basename($file, '.php');
            if (! class_exists($className)) {
                continue;
            }

            $reflection = new \ReflectionClass($className);

            // Skip abstract classes and those that don't declare canView() themselves
            // (i.e. only include widgets that opted in to permission-gating)
            if ($reflection->isAbstract()) {
                continue;
            }

            if (! $reflection->hasMethod('canView')) {
                continue;
            }

            // The method must be declared on THIS class, not inherited from a base widget
            $method = $reflection->getMethod('canView');
            if ($method->getDeclaringClass()->getName() !== $className) {
                continue;
            }

            $widgets[$className] = self::getWidgetDisplayName($className);
        }

        return $widgets;
    }

    /**
     * Get a human-readable label for a widget class.
     */
    private static function getWidgetDisplayName(string $widgetClass): string
    {
        $className = basename(str_replace('\\', '/', $widgetClass));
        // e.g. LowStockAlertsWidget → Low Stock Alerts Widget
        return trim(preg_replace('/([A-Z])/', ' $1', $className));
    }

    /**
     * Get all available pages for permission management
     */
    public static function getAvailablePages(): array
    {
        $pages = [];

        // ── 1. Auto-discover standalone Filament pages ───────────────────────
        $pageFiles = glob(app_path('Filament/Pages/*.php'));
        foreach ($pageFiles as $file) {
            $className = 'App\\Filament\\Pages\\' . basename($file, '.php');
            if (class_exists($className)) {
                $reflection = new \ReflectionClass($className);
                if ($reflection->isSubclassOf(\Filament\Pages\Page::class)) {
                    $pages[$className] = self::getPageDisplayName($className);
                }
            }
        }

        // ── 2. Include any resources that use PermissionService::canAccessPage() ───
        // Resources are not "pages" per se, so they don't live under
        // Filament/Pages; the normal scan above will miss them.  We look for
        // Resource classes and pull their navigation label so that the UI can
        // control access to the index view.
        $resourceFiles = glob(app_path('Filament/Resources/*/*Resource.php')) ?: [];
        foreach ($resourceFiles as $file) {
            // e.g. app/Filament/Resources/ShiftManagement/ShiftManagementResource.php
            $directory = basename(dirname($file));
            $className = "App\\Filament\\Resources\\{$directory}\\" . basename($file, '.php');

            if (! class_exists($className)) {
                continue;
            }

            // Only include resources that actually honour canAccessPage() –
            // a quick heuristic is presence of the method, but you can adjust as
            // your project evolves.
            $reflection = new \ReflectionClass($className);
            if ($reflection->hasMethod('canAccess') || $reflection->hasMethod('can') ) {
                // attempt to read a user‑friendly label; fall back to class name
                $label = null;
                if ($reflection->hasProperty('navigationLabel')) {
                    $prop = $reflection->getProperty('navigationLabel');
                    if ($prop->isStatic()) {
                        $label = $prop->getValue();
                    }
                }
                if (!$label) {
                    $label = class_basename($className);
                }

                $pages[$className] = $label;
            }
        }

        // ── 3. Hand‑picked pages (left here for backwards compatibility) ────
        $manualPages = [
            // Add any classes you want to force into the list. e.g.:
            // App\Filament\Resources\ShiftManagement\ShiftManagementResource::class => 'Shift Management',
        ];

        return array_merge($pages, $manualPages);
    }

    /**
     * Get display name for a page class
     */
    private static function getPageDisplayName(string $pageClass): string
    {
        // Try to get the title from the page class by creating an instance
        if (method_exists($pageClass, 'getTitle')) {
            try {
                $pageInstance = new $pageClass();
                return $pageInstance->getTitle();
            } catch (\Exception $e) {
                // Fallback if instantiation fails
            }
        }

        // Fallback to class name formatting
        $className = basename(str_replace('\\', '/', $pageClass));
        return ucwords(str_replace(['Page', 'Display'], ['', ' Display'], $className));
    }
}