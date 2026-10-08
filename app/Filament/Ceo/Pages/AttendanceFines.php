<?php

namespace App\Filament\Ceo\Pages;

use App\Filament\Pages\MonthlyFinesReport;
use BackedEnum;
use UnitEnum;

/**
 * Monthly attendance fines for the ceo panel.
 *
 * The figure an owner actually wants: what a month of these rules would come
 * to, per person, with shadow kept apart from live. Inherits the admin page
 * so the two can never show different totals.
 *
 * Read-only by construction — the page has no action that writes anything,
 * and the settings that decide the amounts live in the admin panel behind
 * super_admin.
 */
class AttendanceFines extends MonthlyFinesReport
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Attendance Fines';

    protected static ?string $title = 'Attendance Fines';

    protected static ?string $slug = 'attendance-fines';

    public static function canAccess(): bool
    {
        return true;
    }
}
