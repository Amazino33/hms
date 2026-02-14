<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;

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
     * Generic role checker
     */
    public static function hasRole(array $roles): bool
    {
        $user = Auth::user();

        if (!$user) {
            return false;
        }

        return $user->hasRole($roles);
    }
}