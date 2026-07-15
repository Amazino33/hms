<?php

namespace App\Filament\Ceo\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Structural read-only guarantee: these overrides make every mutation
 * entry point return false regardless of Shield/permission configuration
 * — combined with getPages() never registering create/edit routes, there
 * is no mutation path into a CEO resource at all, config or no config.
 */
trait CeoReadOnlyResource
{
    /**
     * Several of these models (Order, Shift, StaffDebt, Booking, ...)
     * already have a Shield-generated Policy class from the admin panel's
     * RBAC — Laravel resolves policies by model class, not by panel, so
     * without this override those policies would silently apply here too
     * and deny a ceo-role user who (correctly) holds none of the admin
     * panel's ViewAny/View permissions. Access to this panel is already
     * fully gated at User::canAccessPanel() — every resource inside it is
     * visible to whoever got in, full stop.
     */
    public static function canViewAny(): bool
    {
        return true;
    }

    public static function canView(Model $record): bool
    {
        return true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
