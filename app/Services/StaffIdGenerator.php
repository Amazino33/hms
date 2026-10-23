<?php

namespace App\Services;

use App\Models\User;

/**
 * Permanent staff IDs: SLM-0001, SLM-0002, … issued once, at approval, and
 * never changed (User::booting() refuses an edit) or reused. Reuse is
 * impossible because users are never deleted, so the highest number ever
 * issued is always still in the table.
 *
 * Only codes in this exact format count towards the sequence. The handful
 * of free-text staff codes typed in before this existed are left alone and
 * cannot collide, because users.staff_code is unique.
 */
class StaffIdGenerator
{
    public const PREFIX = 'SLM-';

    public const DIGITS = 4;

    public static function isIssued(?string $code): bool
    {
        return $code !== null && preg_match('/^'.preg_quote(self::PREFIX, '/').'\d{'.self::DIGITS.',}$/', $code) === 1;
    }

    /**
     * The next unused ID. Call inside the transaction that saves it: the
     * rows are locked so two approvals at the same moment cannot both read
     * the same highest number.
     */
    public static function next(): string
    {
        $highest = User::where('staff_code', 'like', self::PREFIX.'%')
            ->lockForUpdate()
            ->pluck('staff_code')
            ->filter(fn ($code) => self::isIssued($code))
            ->map(fn ($code) => (int) substr($code, strlen(self::PREFIX)))
            ->max() ?? 0;

        return self::PREFIX.str_pad((string) ($highest + 1), self::DIGITS, '0', STR_PAD_LEFT);
    }
}
