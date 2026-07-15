<?php

namespace App\Services;

use App\Models\CashDrop;
use App\Models\CashierSession;
use App\Models\CashierSessionOutflow;
use App\Models\Shift;
use App\Models\StaffDebt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The custody chain's third link. Deliberately does NOT gate the cashier
 * on anything — an unclosed or gap-carrying previous session never blocks
 * currentOrOpen() from handing her a fresh one; that asymmetry (she's
 * never gated, everyone else can be) is a spec requirement, not an
 * oversight. Unclosed sessions surface on the supervisor dashboard
 * instead (built separately).
 */
class CashierSessionService
{
    public function currentOrOpen(User $cashier): CashierSession
    {
        $open = CashierSession::where('user_id', $cashier->id)->where('status', 'open')->first();

        return $open ?? CashierSession::create([
            'user_id' => $cashier->id,
            'status' => 'open',
            'opened_at' => now(),
        ]);
    }

    /**
     * Cash she confirmed at settlements + drops she received, minus
     * outflows she's logged — always derived live, within
     * [opened_at, declared_at ?? now()]. Once she's declared close, the
     * window is frozen at that instant regardless of how long the
     * supervisor takes to actually get to confirming it.
     */
    public function accruedCash(CashierSession $session): float
    {
        // The upper bound's inclusivity has to flip depending on whether
        // the window is still live or already frozen — with second-level
        // DB timestamp precision, an action can round to the exact same
        // stored second as "now" or as declared_at:
        //  - live (no declared_at yet): inclusive, so something confirmed
        //    in this same second still counts, as it should.
        //  - frozen (declared_at set): exclusive, so something confirmed
        //    in that same second AFTER she declared close does NOT count,
        //    even though it would round to an equal stored value.
        $fromSettlements = (float) Shift::where('cash_confirmed_by', $session->user_id)
            ->where('cash_confirmed_at', '>=', $session->opened_at)
            ->where(function ($query) use ($session) {
                $session->declared_at
                    ? $query->where('cash_confirmed_at', '<', $session->declared_at)
                    : $query->where('cash_confirmed_at', '<=', now());
            })
            ->sum('cashier_counted_cash');

        $fromDrops = (float) CashDrop::where('received_by', $session->user_id)
            ->where('status', 'confirmed')
            ->where('confirmed_at', '>=', $session->opened_at)
            ->where(function ($query) use ($session) {
                $session->declared_at
                    ? $query->where('confirmed_at', '<', $session->declared_at)
                    : $query->where('confirmed_at', '<=', now());
            })
            ->sum('confirmed_amount');

        $outflows = (float) $session->outflows()->sum('amount');

        return $fromSettlements + $fromDrops - $outflows;
    }

    public function logOutflow(CashierSession $session, float $amount, string $type, string $note, int $userId): CashierSessionOutflow
    {
        if (! $session->isOpen()) {
            throw new \Exception('This session is not open.');
        }

        if ($amount <= 0) {
            throw new \Exception('Amount must be greater than zero.');
        }

        if (! in_array($type, ['deposit', 'handover'], true)) {
            throw new \Exception('Invalid outflow type.');
        }

        return CashierSessionOutflow::create([
            'cashier_session_id' => $session->id,
            'amount' => $amount,
            'type' => $type,
            'note' => $note,
            'created_by' => $userId,
        ]);
    }

    public function declareClose(CashierSession $session, float $declaredClosingCash, int $cashierId): CashierSession
    {
        if (! $session->isOpen()) {
            throw new \Exception('This session is not open.');
        }

        $session->update([
            'status' => 'pending_supervisor',
            'declared_at' => now(),
            'declared_closing_cash' => $declaredClosingCash,
        ]);

        return $session->fresh();
    }

    /**
     * Blind, same pattern as the settlement channel confirmations: this
     * method never reads declared_closing_cash before recording the
     * supervisor's own count — the gap is computed from her count against
     * the (frozen, at declare time) system-accrued figure.
     */
    public function confirmCloseBySupervisor(CashierSession $session, float $supervisorCountedCash, int $supervisorId): CashierSession
    {
        return DB::transaction(function () use ($session, $supervisorCountedCash, $supervisorId) {
            $session = CashierSession::where('id', $session->id)->lockForUpdate()->firstOrFail();

            if ($session->status !== 'pending_supervisor') {
                throw new \Exception('This session is not awaiting supervisor close-out.');
            }

            $expected = $this->accruedCash($session);
            $gap = round($supervisorCountedCash - $expected, 2);

            $session->update([
                'status' => 'closed',
                'closed_at' => now(),
                'supervisor_counted_cash' => $supervisorCountedCash,
                'gap' => $gap,
                'closed_by' => $supervisorId,
            ]);

            if ($gap < -0.01) {
                StaffDebt::create([
                    'user_id' => $session->user_id,
                    'amount' => abs($gap),
                    'reason' => 'cashier_session_shortfall',
                    'status' => 'open',
                    'created_by' => $supervisorId,
                    'notes' => "Cashier session #{$session->id} close-out gap",
                ]);
            }

            activity('cashier_session')
                ->performedOn($session)
                ->causedBy(User::find($supervisorId))
                ->withProperties(['gap' => $gap])
                ->log('Cashier session closed');

            return $session->fresh();
        });
    }
}
