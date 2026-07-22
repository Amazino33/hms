<?php

namespace App\Services;

use App\Models\FolioLine;
use App\Models\OrderPayment;
use App\Models\Shift;
use App\Models\ShiftChannelConfirmation;
use App\Models\StaffDebt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The cashier's channel-by-channel settlement confirmation — replaces the
 * old single-step "supervisor confirms and closes" action for both waiter
 * and receptionist shifts. A settlement reaches 'confirmed' only once
 * every channel present has been confirmed and no flag is open;
 * StaffDebt is created at that moment from the cashier-counted cash, not
 * the staff member's own declaration (which is never read here at all —
 * blind by construction, not by hiding a value the code still has).
 *
 * Waiter shifts that served BOTH bar and kitchen orders confirm cash/POS
 * per destination instead of one combined figure (usesChannelSplit()) —
 * a waiter who only ever served one destination that shift has nothing
 * meaningful to split, so the original single-figure flow below still
 * applies to them unchanged, same as it always has for receptionists.
 */
class CashierSettlementService
{
    public function expectedCash(Shift $shift): float
    {
        return $shift->type === 'receptionist'
            ? (new ReceptionistShiftService)->expectedCashRemittance($shift)
            : (new ShiftAccountingService)->expectedCashRemittance($shift);
    }

    public function expectedPosMachine(Shift $shift): float
    {
        return $shift->type === 'receptionist'
            ? (new ReceptionistShiftService)->expectedPosMachineTotal($shift)
            : (new ShiftAccountingService)->expectedPosMachineTotal($shift);
    }

    /**
     * A waiter shift confirms bar/kitchen separately only once it actually
     * served both — otherwise (receptionist always, or a waiter who only
     * served one destination) the single combined-figure flow applies.
     */
    public function usesChannelSplit(Shift $shift): bool
    {
        return $shift->type === 'waiter'
            && count((new ShiftAccountingService)->destinationsWithActivity($shift)) > 1;
    }

    public function activeDestinations(Shift $shift): array
    {
        return (new ShiftAccountingService)->destinationsWithActivity($shift);
    }

    /**
     * The cashier's own physically-counted cash figure. Never receives or
     * reads $shift->declared_cash — the blind guarantee holds because
     * this method has no access to it, not because a caller remembered to
     * hide it.
     */
    public function confirmCash(Shift $shift, float $cashierCountedCash, int $confirmingUserId): Shift
    {
        return DB::transaction(function () use ($shift, $cashierCountedCash, $confirmingUserId) {
            $shift = Shift::where('id', $shift->id)->lockForUpdate()->firstOrFail();
            $this->assertAwaitingCashier($shift);

            if ($shift->cash_confirmed_at) {
                throw new \Exception('Cash has already been confirmed for this settlement.');
            }

            $shift->update([
                'cashier_counted_cash' => $cashierCountedCash,
                'cash_confirmed_by' => $confirmingUserId,
                'cash_confirmed_at' => now(),
            ]);

            return $this->finalizeIfComplete($shift->fresh(), $confirmingUserId);
        });
    }

    /**
     * A mismatch never auto-creates debt — it flags for supervisor ruling,
     * since a POS-machine dispute isn't automatically the staff member's
     * fault (could be a batch timing issue, a machine fault, etc.).
     */
    public function confirmPos(Shift $shift, float $machineAmount, int $confirmingUserId): Shift
    {
        return DB::transaction(function () use ($shift, $machineAmount, $confirmingUserId) {
            $shift = Shift::where('id', $shift->id)->lockForUpdate()->firstOrFail();
            $this->assertAwaitingCashier($shift);

            if ($shift->pos_confirmed_at) {
                throw new \Exception('The POS machine total has already been confirmed for this settlement.');
            }

            $expected = $this->expectedPosMachine($shift);
            $matches = abs($machineAmount - $expected) < 0.01;

            $shift->update([
                'pos_machine_confirmed_amount' => $machineAmount,
                'pos_confirmed_by' => $confirmingUserId,
                'pos_confirmed_at' => now(),
                'pos_flagged' => ! $matches,
            ]);

            return $this->finalizeIfComplete($shift->fresh(), $confirmingUserId);
        });
    }

    /**
     * Confirms one destination's one channel (e.g. "bar" "cash") — the
     * split equivalent of confirmCash()/confirmPos() above, only ever used
     * once usesChannelSplit() is true for this shift.
     */
    public function confirmChannelForDestination(Shift $shift, string $destination, string $channel, float $amount, int $confirmingUserId): Shift
    {
        $this->assertValidDestinationChannel($destination, $channel);

        return DB::transaction(function () use ($shift, $destination, $channel, $amount, $confirmingUserId) {
            $shift = Shift::where('id', $shift->id)->lockForUpdate()->firstOrFail();
            $this->assertAwaitingCashier($shift);

            if (! $this->usesChannelSplit($shift)) {
                throw new \Exception('This shift does not use a bar/kitchen split settlement.');
            }

            $existing = ShiftChannelConfirmation::where('shift_id', $shift->id)
                ->where('destination', $destination)
                ->where('channel', $channel)
                ->first();

            if ($existing && $existing->confirmed_at) {
                throw new \Exception(ucfirst($channel)." for {$destination} has already been confirmed for this settlement.");
            }

            $accounting = new ShiftAccountingService;
            $expected = $channel === 'cash'
                ? $accounting->expectedCashForDestination($shift, $destination)
                : $accounting->expectedPosForDestination($shift, $destination);

            $flagged = $channel === 'pos' && abs($amount - $expected) >= 0.01;

            ShiftChannelConfirmation::updateOrCreate(
                ['shift_id' => $shift->id, 'destination' => $destination, 'channel' => $channel],
                [
                    'expected_amount' => $expected,
                    'confirmed_amount' => $amount,
                    'confirmed_by' => $confirmingUserId,
                    'confirmed_at' => now(),
                    'flagged' => $flagged,
                ]
            );

            return $this->finalizeIfComplete($shift->fresh(), $confirmingUserId);
        });
    }

    /**
     * True the instant there's nothing left to verify — a shift with no
     * transfer payments at all trivially has a complete transfer channel.
     * Waiter shifts check OrderPayment (the new cashier queue, built for
     * this feature); receptionist shifts check FolioLine — the hotel
     * folio's own, already-existing TransferVerification page and its
     * verified flag, reused as-is rather than duplicated.
     */
    public function transferChannelComplete(Shift $shift): bool
    {
        if ($shift->type === 'receptionist') {
            return ! FolioLine::where('shift_id', $shift->id)
                ->where('type', 'payment')
                ->where('payment_method', 'transfer')
                ->where('verified', false)
                ->exists();
        }

        return ! OrderPayment::where('shift_id', $shift->id)
            ->where('method', 'transfer')
            ->where('verified', false)
            ->whereNull('ruling')
            ->exists();
    }

    /**
     * Call after any channel-affecting action (a channel confirm, or a
     * transfer verify/flag-ruling elsewhere) — closes the settlement the
     * moment every channel is done and no flag is open, otherwise a no-op.
     */
    public function finalizeIfComplete(Shift $shift, int $actingUserId): Shift
    {
        if ($shift->status !== 'awaiting_cashier') {
            return $shift;
        }

        if ($this->usesChannelSplit($shift)) {
            return $this->finalizeChannelSplit($shift, $actingUserId);
        }

        if (! $shift->cash_confirmed_at || ! $shift->pos_confirmed_at) {
            return $shift;
        }

        if (! $this->transferChannelComplete($shift)) {
            return $shift;
        }

        if ($shift->hasOpenFlag()) {
            return $shift;
        }

        $expectedCash = $this->expectedCash($shift);
        $variance = round((float) $shift->cashier_counted_cash - $expectedCash, 2);
        $cashShortfall = $variance < -0.01 ? abs($variance) : 0.0;

        $chargedAmount = $this->chargedFlagTotal($shift);
        $totalDebt = round($cashShortfall + $chargedAmount, 2);

        $shift->update([
            'expected_cash' => $expectedCash,
            'expected_pos' => $this->expectedPosMachine($shift),
            'cash_variance' => $variance,
            'surplus_amount' => $variance > 0 ? $variance : 0,
            'settled_at' => now(),
            'status' => 'confirmed',
        ]);

        $this->recordDebtAndLog($shift, $cashShortfall, $chargedAmount, $totalDebt, $actingUserId, 'Settlement confirmed');

        return $shift->fresh();
    }

    /**
     * The bar/kitchen equivalent of the block above — complete once every
     * active destination has confirmed both cash and POS, then aggregates
     * onto the same shifts.cashier_counted_cash / pos_machine_confirmed_amount
     * / expected_cash / expected_pos columns so every existing consumer
     * (CEO reports, Waiter Ledger, Shift Management) keeps reading one
     * number without needing to know channelConfirmations rows exist.
     * cash_confirmed_at/pos_confirmed_at are deliberately left null here —
     * those timestamps only ever meant something for the single-figure
     * flow; the channelConfirmations rows are the real record for a split
     * settlement.
     */
    private function finalizeChannelSplit(Shift $shift, int $actingUserId): Shift
    {
        $activeDestinations = $this->activeDestinations($shift);
        $confirmations = $shift->channelConfirmations;

        foreach ($activeDestinations as $destination) {
            foreach (['cash', 'pos'] as $channel) {
                $row = $confirmations->first(fn ($c) => $c->destination === $destination && $c->channel === $channel);
                if (! $row || ! $row->confirmed_at) {
                    return $shift;
                }
            }
        }

        if (! $this->transferChannelComplete($shift)) {
            return $shift;
        }

        if ($shift->hasOpenFlag()) {
            return $shift;
        }

        $totalConfirmedCash = (float) $confirmations->where('channel', 'cash')->sum('confirmed_amount');
        $totalConfirmedPos = (float) $confirmations->where('channel', 'pos')->sum('confirmed_amount');
        $totalExpectedCash = (float) $confirmations->where('channel', 'cash')->sum('expected_amount');
        $totalExpectedPos = (float) $confirmations->where('channel', 'pos')->sum('expected_amount');

        $variance = round($totalConfirmedCash - $totalExpectedCash, 2);
        $cashShortfall = $variance < -0.01 ? abs($variance) : 0.0;

        $chargedAmount = $this->chargedFlagTotal($shift);
        $totalDebt = round($cashShortfall + $chargedAmount, 2);

        $shift->update([
            'cashier_counted_cash' => $totalConfirmedCash,
            'pos_machine_confirmed_amount' => $totalConfirmedPos,
            'expected_cash' => $totalExpectedCash,
            'expected_pos' => $totalExpectedPos,
            'cash_variance' => $variance,
            'surplus_amount' => $variance > 0 ? $variance : 0,
            'settled_at' => now(),
            'status' => 'confirmed',
        ]);

        $this->recordDebtAndLog($shift, $cashShortfall, $chargedAmount, $totalDebt, $actingUserId, 'Settlement confirmed (bar/kitchen split)');

        return $shift->fresh();
    }

    private function recordDebtAndLog(Shift $shift, float $cashShortfall, float $chargedAmount, float $totalDebt, int $actingUserId, string $logMessage): void
    {
        if ($totalDebt > 0.01) {
            $notes = $cashShortfall > 0 ? 'Cash shortfall: ₦'.number_format($cashShortfall, 2) : null;
            if ($chargedAmount > 0) {
                $notes = trim(($notes ? $notes.'. ' : '').'Charged flags (supervisor-ruled): ₦'.number_format($chargedAmount, 2));
            }

            StaffDebt::create([
                'user_id' => $shift->user_id,
                'shift_id' => $shift->id,
                'amount' => $totalDebt,
                'reason' => $shift->type === 'receptionist' ? 'reception_shortfall' : 'shift_shortfall',
                'status' => 'open',
                'created_by' => $actingUserId,
                'notes' => $notes,
            ]);
        }

        activity('shift')
            ->performedOn($shift)
            ->causedBy(User::find($actingUserId))
            ->withProperties(['variance' => $shift->cash_variance, 'charged_flags' => $chargedAmount])
            ->log($logMessage);
    }

    /**
     * The sum of everything a supervisor ruled 'charge' against this
     * settlement — a POS-machine dispute charged to the staff member
     * (shift-wide scalar for a non-split settlement, or per-destination
     * channelConfirmations rows for a split one), plus (waiter shifts
     * only; receptionist transfers resolve through the existing hotel
     * folio reject flow, which never uses this ruling) any transfer
     * payments charged individually.
     */
    private function chargedFlagTotal(Shift $shift): float
    {
        if ($this->usesChannelSplit($shift)) {
            $total = (float) $shift->channelConfirmations()
                ->where('channel', 'pos')
                ->where('ruling', 'charge')
                ->get()
                ->sum(fn (ShiftChannelConfirmation $c) => abs((float) $c->confirmed_amount - (float) $c->expected_amount));
        } else {
            $total = $shift->pos_ruling === 'charge'
                ? abs((float) $shift->pos_machine_confirmed_amount - $this->expectedPosMachine($shift))
                : 0.0;
        }

        if ($shift->type !== 'receptionist') {
            $total += (float) OrderPayment::where('shift_id', $shift->id)
                ->where('ruling', 'charge')
                ->sum('amount');
        }

        return $total;
    }

    private function assertAwaitingCashier(Shift $shift): void
    {
        if ($shift->status !== 'awaiting_cashier') {
            throw new \Exception('This settlement is not awaiting cashier confirmation.');
        }
    }

    private function assertValidDestinationChannel(string $destination, string $channel): void
    {
        if (! in_array($destination, ['bar', 'kitchen'], true)) {
            throw new \Exception('Invalid destination.');
        }

        if (! in_array($channel, ['cash', 'pos'], true)) {
            throw new \Exception('Invalid channel.');
        }
    }
}
