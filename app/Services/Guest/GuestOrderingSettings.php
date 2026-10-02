<?php

namespace App\Services\Guest;

use App\Models\User;
use App\Services\MenuPhotoProcessor;
use App\Services\SettingsService;
use App\Support\NigerianPhone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * Single-value guest-ordering settings (Phase 1B), stored the way every
 * other venue setting is: key/value rows through SettingsService (cached,
 * activity-logged). Lists — the transfer accounts — live in their own table.
 */
class GuestOrderingSettings
{
    public const RECEPTION_WHATSAPP = 'guest_reception_whatsapp';

    public const SPECIALS_TEXT = 'guest_specials_text';

    public const SPECIALS_IMAGE = 'guest_specials_image_path';

    public const SPECIALS_ACTIVE = 'guest_specials_active';

    public const SPECIALS_STARTS_AT = 'guest_specials_starts_at';

    public const SPECIALS_ENDS_AT = 'guest_specials_ends_at';

    public const SPECIALS_TEXT_MAX = 120;

    /** e.g. "2348012345678", or null when not set. */
    public static function receptionWhatsapp(): ?string
    {
        return SettingsService::get(self::RECEPTION_WHATSAPP) ?: null;
    }

    /**
     * @throws \Exception when the number isn't a Nigerian mobile
     */
    public static function setReceptionWhatsapp(?string $input, User $actor): void
    {
        if (blank($input)) {
            SettingsService::set(self::RECEPTION_WHATSAPP, '', 'string', $actor->id);

            return;
        }

        $international = NigerianPhone::toInternational($input);

        if (! $international) {
            throw new \Exception('That is not a Nigerian mobile number. Enter it like 08012345678.');
        }

        SettingsService::set(self::RECEPTION_WHATSAPP, $international, 'string', $actor->id);
    }

    /**
     * @return array{text: ?string, image_path: ?string, active: bool, starts_at: ?CarbonImmutable, ends_at: ?CarbonImmutable}
     */
    public static function specials(): array
    {
        return [
            'text' => SettingsService::get(self::SPECIALS_TEXT) ?: null,
            'image_path' => SettingsService::get(self::SPECIALS_IMAGE) ?: null,
            'active' => SettingsService::getBool(self::SPECIALS_ACTIVE),
            'starts_at' => self::instant(SettingsService::get(self::SPECIALS_STARTS_AT)),
            'ends_at' => self::instant(SettingsService::get(self::SPECIALS_ENDS_AT)),
        ];
    }

    /**
     * What the guest menu should show right now, or null — switched on, has
     * something to say, and inside its optional time window.
     *
     * @return array{text: ?string, image_url: ?string}|null
     */
    public static function liveSpecials(): ?array
    {
        $specials = self::specials();
        $now = CarbonImmutable::now();

        if (! $specials['active'] || (blank($specials['text']) && blank($specials['image_path']))) {
            return null;
        }

        if (($specials['starts_at'] && $now->lt($specials['starts_at'])) || ($specials['ends_at'] && $now->gte($specials['ends_at']))) {
            return null;
        }

        return [
            'text' => $specials['text'],
            'image_url' => $specials['image_path'] ? Storage::disk(MenuPhotoProcessor::DISK)->url($specials['image_path']) : null,
        ];
    }

    /**
     * @param  array{text: ?string, image_path: ?string, active: bool, starts_at: mixed, ends_at: mixed}  $specials
     */
    public static function saveSpecials(array $specials, User $actor): void
    {
        $text = trim((string) ($specials['text'] ?? ''));

        if (mb_strlen($text) > self::SPECIALS_TEXT_MAX) {
            throw new \Exception('The specials banner can be at most '.self::SPECIALS_TEXT_MAX.' characters.');
        }

        $starts = self::instant($specials['starts_at'] ?? null);
        $ends = self::instant($specials['ends_at'] ?? null);

        if ($starts && $ends && $ends->lte($starts)) {
            throw new \Exception('The specials banner has to end after it starts.');
        }

        $previousImage = SettingsService::get(self::SPECIALS_IMAGE) ?: null;
        $image = $specials['image_path'] ?? null;

        SettingsService::set(self::SPECIALS_TEXT, $text, 'string', $actor->id);
        SettingsService::set(self::SPECIALS_IMAGE, (string) $image, 'string', $actor->id);
        SettingsService::setBool(self::SPECIALS_ACTIVE, (bool) ($specials['active'] ?? false), $actor->id);
        SettingsService::set(self::SPECIALS_STARTS_AT, $starts?->toIso8601String() ?? '', 'string', $actor->id);
        SettingsService::set(self::SPECIALS_ENDS_AT, $ends?->toIso8601String() ?? '', 'string', $actor->id);

        if ($previousImage && $previousImage !== $image) {
            Storage::disk(MenuPhotoProcessor::DISK)->delete($previousImage);
        }
    }

    /** Stored and compared in UTC; the admin form shows it in venue time. */
    private static function instant(mixed $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        return CarbonImmutable::parse($value)->utc();
    }
}
