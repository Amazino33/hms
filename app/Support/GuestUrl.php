<?php

namespace App\Support;

/**
 * The only place a guest URL is built (Phase 1B). Guest links name a table
 * or room by its random qr_token — never by id or number — and are always
 * absolute, because they end up printed on paper.
 */
class GuestUrl
{
    public static function forToken(string $token): string
    {
        return self::base().'/m/'.rawurlencode($token);
    }

    /** The general, menu-only link for the entrance, posters and WhatsApp status. */
    public static function menu(): string
    {
        return self::base().'/menu';
    }

    private static function base(): string
    {
        return rtrim((string) config('app.url'), '/');
    }
}
