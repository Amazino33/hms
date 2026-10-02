<?php

namespace App\Support;

/**
 * Nigerian mobile numbers, normalised to the international digits-only form
 * WhatsApp links need: 2348012345678 (Phase 1B).
 *
 * Accepts what people actually type — 08012345678, 0801 234 5678,
 * +234 801 234 5678, 2348012345678, 8012345678 — and rejects anything that
 * isn't a Nigerian mobile (07x, 08x or 09x followed by 8 more digits).
 */
class NigerianPhone
{
    public static function toInternational(?string $input): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $input);

        $national = match (true) {
            str_starts_with($digits, '234') && strlen($digits) === 13 => substr($digits, 3),
            str_starts_with($digits, '0') && strlen($digits) === 11 => substr($digits, 1),
            strlen($digits) === 10 => $digits,
            default => null,
        };

        if ($national === null || ! preg_match('/^[789][01]\d{8}$/', $national)) {
            return null;
        }

        return '234'.$national;
    }
}
