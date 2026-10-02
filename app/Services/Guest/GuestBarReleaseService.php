<?php

namespace App\Services\Guest;

use App\Models\GuestRequest;
use App\Models\GuestRequestItem;
use App\Models\GuestTableSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The bar's side of guest ordering (Phase 3). A confirmed request's drink
 * lines wait 'at_bar' until the bartender releases them; releasing creates
 * the real bar order (stock leaves the bar right then, as for any bar
 * order) under the assigned WAITER's identity and shift (D2), with the
 * guest's locked prices (D18). The bartender is recorded on the request
 * lines only (released_by_user_id), never on the order.
 *
 * Phase 7B (D33): the bar never sees a separate "release" step. A guest
 * card's one Mark Ready runs markReady(): the release below (unchanged
 * rules: D1, D2, D3, D18, D24) AND the shared bar ready service, in one
 * transaction. release() is private — markReady() is its only caller.
 *
 * A ROOM request (Phase 5, D24) has no waiter: Release creates the room
 * order (billed to the stay's folio) and runs bar Mark Ready
 * (BarOrderService) in the same transaction, so stock and the charge both
 * happen at release. The lines then wait for a porter.
 */
class GuestBarReleaseService
{
    public const REASON_PRESETS = ['Out of stock', 'Wrong item', 'Other'];

    public const RETURNED_TO_WAITERS = 'returned_to_waiters';

    public const RELEASED = 'released';

    public const READY = 'ready';

    /** @var array<int, \App\Models\Order> the orders the last markReady() created and marked ready */
    public array $lastOrders = [];

    private ?User $lastBartender = null;

    /**
     * The guest card's one tap (D33): create the real bar order and mark it
     * ready, in ONE transaction. Tables: the order is created (stock leaves
     * the bar then, as for any bar order) and set ready — BarOrderService
     * deducts nothing for a table order. Rooms: the release already ran
     * the ready step with the room's stock and charge (D24); an order that
     * is already ready is never marked twice.
     *
     * @param  User|null  $bartenderChoice  required when more than one bartender shift is open (D1)
     * @return string self::READY, or self::RETURNED_TO_WAITERS when the waiter was off shift (D3)
     *
     * @throws \Exception with a message written for the bar
     */
    public function markReady(GuestRequest $request, ?User $bartenderChoice = null): string
    {
        return DB::transaction(function () use ($request, $bartenderChoice) {
            $this->lastOrders = [];
            $result = $this->release($request, $bartenderChoice);

            if ($result === self::RETURNED_TO_WAITERS) {
                return $result;
            }

            $bar = new \App\Services\BarOrderService;
            $ready = [];

            foreach ($this->lastOrders as $order) {
                $fresh = \App\Models\Order::find($order->id);

                if ($fresh && $fresh->destination === 'bar' && $fresh->status === 'pending') {
                    $fresh = $bar->markReady($fresh->id, $this->lastBartender?->id);
                }

                $ready[] = $fresh;
            }

            $this->lastOrders = array_values(array_filter($ready));

            return self::READY;
        });
    }

    /**
     * Creates the real order — markReady()'s first half. Private: nothing
     * else may call it (D33; GuestPhase7BBoundariesTest).
     *
     * @param  User|null  $bartenderChoice  required when more than one bartender shift is open (D1)
     * @return string self::RELEASED, or self::RETURNED_TO_WAITERS when the waiter was off shift (D3)
     *
     * @throws \Exception with a message written for the bar
     */
    private function release(GuestRequest $request, ?User $bartenderChoice = null): string
    {
        return DB::transaction(function () use ($request, $bartenderChoice) {
            $request = GuestRequest::lockForUpdate()->findOrFail($request->id);
            $lines = $request->items()->where('status', 'at_bar')->lockForUpdate()->get();

            // A double tap: the first Mark Ready already took every line.
            if ($lines->isEmpty()) {
                throw new \Exception("{$request->ref} has already been marked ready.");
            }

            $bartender = $this->creditedBartender($bartenderChoice);
            $this->lastBartender = $bartender;

            if ($request->isRoom()) {
                $this->releaseToRoom($request, $lines, $bartender);

                return self::RELEASED;
            }

            $session = GuestTableSession::lockForUpdate()->find($request->guest_table_session_id);
            $waiter = $session?->assignedWaiter;
            $waiterShift = GuestShifts::activeWaiterShift($waiter);

            // D3 safety net: never create an order under a waiter who has
            // left — hand the drinks back for another waiter to pick up.
            if (! $waiterShift) {
                GuestRequestItem::whereKey($lines->pluck('id'))->update(['status' => 'needs_waiter']);
                $session?->update(['assigned_waiter_user_id' => null, 'assigned_shift_id' => null]);

                return self::RETURNED_TO_WAITERS;
            }

            $this->lastOrders = GuestRequestService::placeOrders($lines, $request, $waiter, $waiterShift);

            GuestRequestItem::whereKey($lines->pluck('id'))->update([
                'status' => 'released',
                'released_by_user_id' => $bartender->id,
                'released_at' => now(),
            ]);

            $session->update(['last_activity_at' => now()]);

            return self::RELEASED;
        });
    }

    /**
     * D24: room order + bar Mark Ready + waiting for a porter, all inside
     * release()'s transaction. The order goes under the receptionist who
     * approved the request; the bartender is credited on the lines.
     *
     * @param  \Illuminate\Support\Collection<int, GuestRequestItem>  $lines
     *
     * @throws \Exception
     */
    private function releaseToRoom(GuestRequest $request, $lines, User $bartender): void
    {
        $approver = $request->confirmedBy ?? $bartender;
        $orders = GuestRequestService::placeOrders($lines, $request, $approver, null);
        $this->lastOrders = $orders;

        GuestRequestItem::whereKey($lines->pluck('id'))->update([
            'status' => 'released',
            'released_by_user_id' => $bartender->id,
            'released_at' => now(),
        ]);

        $bar = new \App\Services\BarOrderService;
        $delivery = new RoomDeliveryService;

        foreach ($orders as $order) {
            if ($order->destination === 'bar') {
                $order = $bar->markReady($order->id, $bartender->id);
            }

            $delivery->readyForDispatch($order);
        }

        \Illuminate\Support\Facades\Cache::forget('bar_display:active_orders');
        \Illuminate\Support\Facades\Cache::forget('bar_display:recent_history');
    }

    /**
     * The bar can give a guest fewer than they asked for (e.g. only one
     * Malta left), never more, and always with a reason the guest sees.
     *
     * @throws \Exception
     */
    public function reduce(GuestRequestItem $line, int $newQty, string $reason, ?string $other = null): GuestRequestItem
    {
        $reason = $this->reasonText($reason, $other);

        return DB::transaction(function () use ($line, $newQty, $reason) {
            $line = GuestRequestItem::lockForUpdate()->findOrFail($line->id);
            $this->assertAtBar($line);

            if ($newQty < 1 || $newQty >= $line->quantity_requested) {
                throw new \Exception("Reduce {$line->name_snapshot} to between 1 and ".($line->quantity_requested - 1).' — use ✕ to remove it completely.');
            }

            $line->update(['quantity_final' => $newQty, 'removed_reason' => "Reduced to {$newQty}: {$reason}"]);

            return $line;
        });
    }

    /**
     * @throws \Exception
     */
    public function remove(GuestRequestItem $line, string $reason, ?string $other = null): GuestRequestItem
    {
        $reason = $this->reasonText($reason, $other);

        return DB::transaction(function () use ($line, $reason) {
            $line = GuestRequestItem::lockForUpdate()->findOrFail($line->id);
            $this->assertAtBar($line);

            $line->update(['status' => 'removed', 'removed_reason' => $reason]);
            GuestRequestService::closeIfNothingLeft($line->request, $reason);

            return $line;
        });
    }

    /**
     * D1: no bartender shift — nobody can release; exactly one — it's
     * theirs; more than one — the bar must say who is releasing.
     *
     * @throws \Exception
     */
    private function creditedBartender(?User $choice): User
    {
        $shifts = GuestShifts::activeBartenderShifts();

        if ($shifts->isEmpty()) {
            throw new \Exception('Start your bartender shift to mark guest orders ready.');
        }

        if ($shifts->count() === 1) {
            return $shifts->first()->user;
        }

        if (! $choice) {
            throw new \Exception('More than one bartender shift is open — choose who is marking this ready.');
        }

        $shift = $shifts->firstWhere('user_id', $choice->id);

        if (! $shift) {
            throw new \Exception("{$choice->name} isn't on an open bartender shift.");
        }

        return $shift->user;
    }

    /**
     * @throws \Exception
     */
    private function reasonText(string $reason, ?string $other): string
    {
        if (! in_array($reason, self::REASON_PRESETS, true)) {
            throw new \Exception('Pick a reason: out of stock, wrong item, or other.');
        }

        if ($reason !== 'Other') {
            return $reason;
        }

        $other = trim((string) $other);

        if ($other === '' || mb_strlen($other) > 100) {
            throw new \Exception('Say what the reason is, in up to 100 characters.');
        }

        return $other;
    }

    /**
     * @throws \Exception
     */
    private function assertAtBar(GuestRequestItem $line): void
    {
        if ($line->status !== 'at_bar') {
            throw new \Exception("{$line->name_snapshot} isn't waiting at the bar any more.");
        }
    }
}
