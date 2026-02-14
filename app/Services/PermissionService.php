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
     * Get all available pages for permission management
     */
    public static function getAvailablePages(): array
    {
        $pages = [];

        // Auto-discover pages from directory
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

        // Add manually registered pages if needed
        $manualPages = [
            // Add any pages that might not be auto-discovered
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