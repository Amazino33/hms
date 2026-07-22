<?php

namespace App\Services;

use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The staff-facing half of a line's lifecycle, after it's been paid.
 * acknowledge()/dispute() are the staff's own act (web-login, on
 * MyPayslips); closeWithReason() is the CEO/manager fallback for a staff
 * member who never responds. A disputed line is deliberately left disputed
 * here — resolving it is PayrollVoidService's job (void-and-reissue), not
 * this service's.
 */
class PayrollAcknowledgementService
{
    public function acknowledge(PayrollLine $line, User $staff): PayrollLine
    {
        if ($line->user_id !== $staff->id) {
            throw new RuntimeException('You can only acknowledge your own payslip.');
        }

        if (! $line->isPaid()) {
            throw new RuntimeException('Only a paid payslip can be acknowledged.');
        }

        return DB::transaction(function () use ($line) {
            $line->update([
                'status' => 'acknowledged',
                'acknowledged_at' => now(),
            ]);

            $this->maybeCloseRun($line->run);

            return $line->fresh();
        });
    }

    public function dispute(PayrollLine $line, User $staff, string $reason, ?float $reportedAmount = null): PayrollLine
    {
        if ($line->user_id !== $staff->id) {
            throw new RuntimeException('You can only dispute your own payslip.');
        }

        if (! $line->isPaid()) {
            throw new RuntimeException('Only a paid payslip can be disputed.');
        }

        if (trim($reason) === '') {
            throw new RuntimeException('A reason is required to dispute a payslip.');
        }

        $line->update([
            'status' => 'disputed',
            'dispute_reason' => $reason,
            'dispute_reported_amount' => $reportedAmount,
        ]);

        return $line->fresh();
    }

    /**
     * CEO/manager fallback close for a staff member who never acknowledges
     * or disputes — leaves an explicit paper trail (closed_reason/
     * closed_by/closed_at) rather than a line stuck pending forever.
     */
    public function closeWithReason(PayrollLine $line, User $closedBy, string $reason): PayrollLine
    {
        if (! $line->isPaid()) {
            throw new RuntimeException('Only a paid payslip can be force-closed.');
        }

        if (trim($reason) === '') {
            throw new RuntimeException('A reason is required to close a payslip without acknowledgement.');
        }

        return DB::transaction(function () use ($line, $closedBy, $reason) {
            $line->update([
                'status' => 'closed_no_ack',
                'closed_reason' => $reason,
                'closed_by' => $closedBy->id,
                'closed_at' => now(),
            ]);

            $this->maybeCloseRun($line->run);

            return $line->fresh();
        });
    }

    /**
     * A run closes once every line is settled one way or another
     * (acknowledged or closed_no_ack). A disputed line blocks closure —
     * it needs PayrollVoidService::voidAndReissue() to resolve.
     */
    protected function maybeCloseRun(PayrollRun $run): void
    {
        if ($run->status !== 'sealed') {
            return;
        }

        $unsettled = $run->lines()
            ->whereNotIn('status', ['acknowledged', 'closed_no_ack'])
            ->exists();

        if (! $unsettled) {
            $run->update(['status' => 'closed']);
        }
    }
}
