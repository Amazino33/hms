<?php

namespace App\Services;

use App\Models\User;

/**
 * Whether the public /join form accepts applications. Off by default: the
 * form is only open while the venue is hiring. Stored like every other
 * runtime setting, through SettingsService (cached, activity-logged).
 */
class StaffApplicationSettings
{
    public const OPEN = 'staff_applications_open';

    public static function isOpen(): bool
    {
        return SettingsService::getBool(self::OPEN, false);
    }

    public static function set(bool $open, User $actor): void
    {
        SettingsService::setBool(self::OPEN, $open, $actor->id);
    }
}
