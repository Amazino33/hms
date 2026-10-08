<?php

namespace App\Filament\Ceo\Pages;

use App\Filament\Pages\AttendanceBoard;
use BackedEnum;
use UnitEnum;

/**
 * The attendance board, read-only, for the ceo panel.
 *
 * Extends the admin page rather than copying it, so the outcome rules, the
 * tiles and the detail slide-over cannot drift between what the owner sees
 * and what the managers see — two screens disagreeing about who was absent
 * would be worse than one screen nobody trusts.
 *
 * canAccess() is open because this panel is gated at User::canAccessPanel():
 * super_admin and the ceo role get in, nobody else can reach it at all. The
 * admin page's own permission check would deny a ceo-only user, who correctly
 * holds none of the admin panel's Shield permissions.
 *
 * Re-evaluating is already super-admin-only on the inherited action, so a ceo
 * sees the figures and cannot change them.
 */
class AttendanceOverview extends AttendanceBoard
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Attendance';

    protected static ?string $title = 'Attendance';

    protected static ?string $slug = 'attendance';

    public static function canAccess(): bool
    {
        return true;
    }
}
