<?php

namespace App\Services;

/**
 * Admin-editable thresholds for the count breakdown, edited on Company
 * Settings and read through SettingsService.
 */
class CountBreakdownSettings
{
    public const SLOW_RELEASE_MINUTES = 'slow_release_minutes';

    public const REPEAT_SHORTAGE_THRESHOLD = 'repeat_shortage_threshold';

    public const REPEAT_SHORTAGE_WINDOW = 'repeat_shortage_window';

    public const DEFAULTS = [
        self::SLOW_RELEASE_MINUTES => 30,
        self::REPEAT_SHORTAGE_THRESHOLD => 3,
        self::REPEAT_SHORTAGE_WINDOW => 5,
    ];

    public static function slowReleaseMinutes(): int
    {
        return self::int(self::SLOW_RELEASE_MINUTES);
    }

    public static function repeatShortageThreshold(): int
    {
        return self::int(self::REPEAT_SHORTAGE_THRESHOLD);
    }

    public static function repeatShortageWindow(): int
    {
        return self::int(self::REPEAT_SHORTAGE_WINDOW);
    }

    private static function int(string $key): int
    {
        $value = (int) SettingsService::get($key, (string) self::DEFAULTS[$key]);

        return $value > 0 ? $value : self::DEFAULTS[$key];
    }
}
