<?php

namespace App\Services;

use App\Models\CountBreakdownLine;
use App\Models\CountVarianceNote;
use App\Models\User;

/**
 * A count participant explaining a variance on their own count. Notes are
 * append-only and close once the manager has ruled on the discrepancy.
 */
class CountVarianceNoteService
{
    /** Hours a variance with nothing for the manager to rule on stays open for notes. */
    private const NO_RULING_GRACE_HOURS = 24;

    public function add(CountBreakdownLine $line, User $author, string $body): CountVarianceNote
    {
        $body = trim($body);
        $session = $line->session;

        if (! $session || ! $session->isReviewed()) {
            throw new \Exception('Explanations can only be added once the count is sealed.');
        }

        if (! $session->isParticipant($author->id)) {
            throw new \Exception('Only staff who took part in this count can add an explanation.');
        }

        if (! $line->hasVariance()) {
            throw new \Exception('This item has no variance to explain.');
        }

        if (! $this->isOpenForNotes($line)) {
            throw new \Exception('The manager has already ruled on this variance, so explanations are closed.');
        }

        if ($body === '') {
            throw new \Exception('Write an explanation before saving.');
        }

        if (mb_strlen($body) > 1000) {
            throw new \Exception('Keep the explanation under 1,000 characters.');
        }

        return CountVarianceNote::create([
            'count_breakdown_line_id' => $line->id,
            'user_id' => $author->id,
            'author_name' => $author->name,
            'body' => $body,
        ]);
    }

    /**
     * Open until the manager records a resolution. A shortage has its own
     * discrepancy line, so it closes when that line is resolved. An item
     * with no discrepancy line (a handover overage) closes once every
     * discrepancy on the count is resolved, or a day after sealing when
     * the count had none to rule on.
     */
    public function isOpenForNotes(CountBreakdownLine $line): bool
    {
        if (! $line->hasVariance()) {
            return false;
        }

        $session = $line->session;
        $discrepancy = $line->sessionItem?->discrepancy;

        if ($discrepancy) {
            return $discrepancy->isOpen();
        }

        if ($session->discrepancies()->exists()) {
            return $session->unresolvedDiscrepancyCount() > 0;
        }

        return $session->reviewed_at !== null
            && $session->reviewed_at->gt(now()->subHours(self::NO_RULING_GRACE_HOURS));
    }
}
