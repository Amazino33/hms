<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Every read/write goes through here, never App\Models\Setting directly —
 * keeps caching and activity-logging consistent no matter which of the
 * (currently few, likely growing) runtime settings is touched.
 */
class SettingsService
{
    private const CACHE_PREFIX = 'setting:';

    private const CACHE_TTL = 3600;

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return Cache::remember(self::CACHE_PREFIX . $key, self::CACHE_TTL, function () use ($key, $default) {
            return Setting::where('key', $key)->value('value') ?? $default;
        });
    }

    public static function setBool(string $key, bool $value, int $updatedByUserId): void
    {
        self::set($key, $value ? '1' : '0', 'boolean', $updatedByUserId);
    }

    public static function set(string $key, string $value, string $type, int $updatedByUserId): void
    {
        $setting = Setting::where('key', $key)->first();
        $old = $setting?->value;

        $setting = Setting::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'type' => $type, 'updated_by' => $updatedByUserId],
        );

        Cache::forget(self::CACHE_PREFIX . $key);

        activity('setting')
            ->performedOn($setting)
            ->causedBy(User::find($updatedByUserId))
            ->withProperties(['key' => $key, 'old' => $old, 'new' => $value])
            ->log("Setting '{$key}' changed");
    }
}
