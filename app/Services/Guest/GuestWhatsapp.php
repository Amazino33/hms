<?php

namespace App\Services\Guest;

use App\Models\GuestRequest;

/**
 * The WhatsApp hand-off for a room order (Phase 5): after the request is
 * saved, the guest's phone opens WhatsApp to reception with the order
 * already typed. Built here, server-side, in exactly this shape:
 *
 *   🛎 Room 7 order · Ref R7-0423
 *   1x Jollof Rice  ₦4,500
 *   2x Malta (Cold)  ₦2,000
 *      Note: no ice
 *   Total: ₦6,500
 */
class GuestWhatsapp
{
    /** null when the request isn't on WhatsApp, or no reception number is set. */
    public static function orderUrl(GuestRequest $request): ?string
    {
        $number = GuestOrderingSettings::receptionWhatsapp();

        if ($request->channel !== GuestRequest::CHANNEL_WHATSAPP || ! $number) {
            return null;
        }

        return 'https://wa.me/'.$number.'?text='.rawurlencode(self::orderMessage($request));
    }

    public static function orderMessage(GuestRequest $request): string
    {
        $request->loadMissing(['items', 'room']);

        $lines = ['🛎 Room '.$request->room?->number.' order · Ref '.$request->ref];

        foreach ($request->items as $item) {
            $qty = $item->finalQuantity();
            $chips = $item->chips ? ' ('.implode(', ', $item->chips).')' : '';
            $lines[] = "{$qty}x {$item->name_snapshot}{$chips}  ".self::naira((float) $item->unit_price_snapshot * $qty);

            if ($item->note) {
                $lines[] = '   Note: '.$item->note;
            }
        }

        $lines[] = 'Total: '.self::naira((float) $request->total_snapshot);

        return implode("\n", $lines);
    }

    private static function naira(float $amount): string
    {
        return '₦'.number_format(round($amount));
    }
}
