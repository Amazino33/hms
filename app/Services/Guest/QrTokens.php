<?php

namespace App\Services\Guest;

use App\Models\QrTokenRegeneration;
use App\Models\Room;
use App\Models\Table;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The tokens behind every table and room QR code (Phase 1B).
 *
 * A token is 10 random base62 characters from random_int() (a CSPRNG) —
 * never derived from an id or a number — and unique across tables AND
 * rooms together, so /m/{token} always means exactly one place.
 * Regenerating replaces it outright: the old token stops resolving at
 * once and is never stored anywhere, not even in the log.
 */
class QrTokens
{
    public const LENGTH = 10;

    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /** Only the two kinds of place a guest QR code can name. */
    private const SUBJECTS = [Table::class => 'tables', Room::class => 'rooms'];

    public static function generate(): string
    {
        do {
            $token = '';

            for ($i = 0; $i < self::LENGTH; $i++) {
                $token .= self::ALPHABET[random_int(0, 61)];
            }
        } while (self::taken($token));

        return $token;
    }

    /**
     * The table or room a scanned token names, or null — for an unknown
     * token and a replaced one alike, so a caller can never tell them apart.
     */
    public static function resolve(string $token): Table|Room|null
    {
        if (! preg_match('/^[0-9A-Za-z]{'.self::LENGTH.'}$/', $token)) {
            return null;
        }

        return Table::where('qr_token', $token)->first()
            ?? Room::where('qr_token', $token)->first();
    }

    /**
     * New token for a table or room, logged with who and why. The old one
     * is gone the moment this commits.
     */
    public static function regenerate(Table|Room $subject, User $actor, string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new \Exception('Say why this QR code is being replaced — it is kept in the log.');
        }

        return DB::transaction(function () use ($subject, $actor, $reason) {
            $subject = $subject::query()->lockForUpdate()->findOrFail($subject->getKey());
            $token = self::generate();

            $subject->forceFill(['qr_token' => $token])->save();

            QrTokenRegeneration::create([
                'subject_type' => $subject::class,
                'subject_id' => $subject->getKey(),
                'regenerated_by' => $actor->id,
                'reason' => $reason,
            ]);

            return $token;
        });
    }

    private static function taken(string $token): bool
    {
        foreach (self::SUBJECTS as $table) {
            if (DB::table($table)->where('qr_token', $token)->exists()) {
                return true;
            }
        }

        return false;
    }
}
