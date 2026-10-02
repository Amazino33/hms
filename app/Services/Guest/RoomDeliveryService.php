<?php

namespace App\Services\Guest;

use App\Models\GuestDeliveryRefusal;
use App\Models\GuestRequestItem;
use App\Models\Order;
use App\Models\User;
use App\Services\RoomOrderService;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Getting a guest's room order to the room (Phase 5) — the only writer of
 * guest_request_items.delivery_status and of guest_delivery_refusals.
 *
 *   ready (bar Release / kitchen Mark Ready) → awaiting_dispatch
 *   reception sends it with a porter (or "Reception") → out_for_delivery
 *   → delivered, or → refused (D26: a refusal record; nothing reversed yet)
 *
 * Guest room orders have this ONE delivery flow (owner decision
 * 2026-10-02): the order's own porter fields are kept in step — dispatch
 * stamps picked_up_by/at, delivery marks the order served — and Porter
 * Deliveries leaves these orders out.
 */
class RoomDeliveryService
{
    public const RECEPTION_ROLES = RoomRequestApprovalService::ROLES;

    public const MANAGER_ROLES = RoomOrderService::CANCEL_AFTER_READY_ROLES;

    public const BAR_ROLES = ['bartender', 'manager', 'admin', 'super_admin'];

    /**
     * The order is made: its room-request lines now wait for a porter.
     * Called by the bar Release (drinks) and by GuestRoomOrderObserver when
     * the kitchen marks a room order Ready (food).
     */
    public function readyForDispatch(Order $order): int
    {
        if (! $order->booking_id) {
            return 0;
        }

        return GuestRequestItem::where('order_id', $order->id)
            ->whereNull('delivery_status')
            ->whereIn('status', ['ordered', 'released', 'at_bar'])
            ->whereHas('request', fn ($q) => $q->whereNotNull('room_id'))
            ->update(['delivery_status' => GuestRequestItem::AWAITING_DISPATCH]);
    }

    /**
     * @param  Collection<int, GuestRequestItem>|array<int, int>  $lines
     *
     * @throws \Exception
     */
    public function dispatch($lines, ?User $porter, User $receptionist): Collection
    {
        $this->assertRole($receptionist, self::RECEPTION_ROLES, 'Only reception or a manager can send room deliveries.');

        if ($porter && (! $porter->hasRole('porter') || $porter->left_at)) {
            throw new \Exception("{$porter->name} isn't an active porter.");
        }

        return DB::transaction(function () use ($lines, $porter, $receptionist) {
            $lines = $this->lock($lines, GuestRequestItem::AWAITING_DISPATCH, 'ready to send');

            GuestRequestItem::whereKey($lines->pluck('id'))->update([
                'delivery_status' => GuestRequestItem::OUT_FOR_DELIVERY,
                'porter_user_id' => $porter?->id,
                'dispatched_at' => now(),
            ]);

            // Keep the order's own custody fields (Porter Deliveries) in step.
            Order::whereKey($lines->pluck('order_id')->unique())->whereNull('picked_up_at')->update([
                'picked_up_by' => ($porter ?? $receptionist)->id,
                'picked_up_at' => now(),
            ]);

            return $lines->fresh();
        });
    }

    /**
     * @param  Collection<int, GuestRequestItem>|array<int, int>  $lines
     *
     * @throws \Exception
     */
    public function delivered($lines, User $receptionist): Collection
    {
        $this->assertRole($receptionist, self::RECEPTION_ROLES, 'Only reception or a manager can confirm room deliveries.');

        return DB::transaction(function () use ($lines) {
            $lines = $this->lock($lines, GuestRequestItem::OUT_FOR_DELIVERY, 'out for delivery');

            GuestRequestItem::whereKey($lines->pluck('id'))->update([
                'delivery_status' => GuestRequestItem::DELIVERED,
                'delivered_at' => now(),
            ]);

            $this->serveFinishedOrders($lines->pluck('order_id')->unique());

            return $lines->fresh();
        });
    }

    /**
     * The guest refused the delivery (D26). One refusal per order: drinks
     * wait for the bar to confirm the bottles came back, food goes straight
     * to a manager. Nothing is reversed until a manager approves.
     *
     * @param  Collection<int, GuestRequestItem>|array<int, int>  $lines
     *
     * @throws \Exception
     */
    public function refused($lines, string $reason, User $receptionist): Collection
    {
        $this->assertRole($receptionist, self::RECEPTION_ROLES, 'Only reception or a manager can record a refused delivery.');
        $reason = trim($reason);

        if ($reason === '') {
            throw new \Exception('Say why the guest refused it.');
        }

        return DB::transaction(function () use ($lines, $reason, $receptionist) {
            $lines = $this->lock($lines, GuestRequestItem::OUT_FOR_DELIVERY, 'out for delivery');
            $refusals = collect();

            foreach ($lines->groupBy('order_id') as $orderId => $orderLines) {
                $order = Order::lockForUpdate()->findOrFail($orderId);

                // A refusal cancels the whole order when approved — so the
                // whole order must be refused together.
                $others = GuestRequestItem::where('order_id', $orderId)->whereNotIn('id', $orderLines->pluck('id'))
                    ->whereNotIn('status', ['removed', 'cancelled'])->exists();

                if ($others) {
                    throw new \Exception("Refuse everything in order {$order->order_number} together — it is cancelled as one if a manager approves.");
                }

                GuestRequestItem::whereKey($orderLines->pluck('id'))->update(['delivery_status' => GuestRequestItem::REFUSED]);

                $refusals->push(GuestDeliveryRefusal::create([
                    'guest_request_id' => $orderLines->first()->guest_request_id,
                    'order_id' => $order->id,
                    'line_ids' => $orderLines->pluck('id')->values()->all(),
                    'station' => $order->destination === 'bar' ? 'bar' : 'kitchen',
                    'reason' => $reason,
                    'recorded_by_user_id' => $receptionist->id,
                    'status' => $order->destination === 'bar'
                        ? GuestDeliveryRefusal::AWAITING_BAR_RETURN
                        : GuestDeliveryRefusal::AWAITING_MANAGER,
                ]));
            }

            DB::afterCommit(fn () => $this->alertManagers($refusals));

            return $refusals;
        });
    }

    /**
     * The bar confirms the refused bottles are back (D26), on the Bar
     * Display. Only then can a manager decide.
     *
     * @throws \Exception
     */
    public function confirmBarReturn(GuestDeliveryRefusal $refusal, User $bartender): GuestDeliveryRefusal
    {
        $this->assertRole($bartender, self::BAR_ROLES, 'Only bar staff or a manager can confirm drinks came back.');

        return DB::transaction(function () use ($refusal, $bartender) {
            $refusal = GuestDeliveryRefusal::lockForUpdate()->findOrFail($refusal->id);

            if ($refusal->status !== GuestDeliveryRefusal::AWAITING_BAR_RETURN) {
                throw new \Exception('This refusal isn\'t waiting for the bar.');
            }

            $refusal->update([
                'status' => GuestDeliveryRefusal::AWAITING_MANAGER,
                'bar_return_confirmed_by_user_id' => $bartender->id,
                'bar_return_confirmed_at' => now(),
            ]);

            return $refusal;
        });
    }

    /**
     * A manager decides (D26). Approve: the room order is cancelled through
     * RoomOrderService::cancel() — its folio charge reversed, drinks
     * restocked, cooked food recorded as waste. Reject: the charge stands
     * and the guest's bill shows the line as delivered.
     *
     * @throws \Exception
     */
    public function decide(GuestDeliveryRefusal $refusal, User $manager, bool $approve, ?string $note = null): GuestDeliveryRefusal
    {
        $this->assertRole($manager, self::MANAGER_ROLES, 'Only a manager can decide a refused delivery.');

        return DB::transaction(function () use ($refusal, $manager, $approve, $note) {
            $refusal = GuestDeliveryRefusal::lockForUpdate()->findOrFail($refusal->id);

            if ($refusal->status === GuestDeliveryRefusal::AWAITING_BAR_RETURN) {
                throw new \Exception('The bar hasn\'t confirmed these drinks came back yet.');
            }

            if ($refusal->status !== GuestDeliveryRefusal::AWAITING_MANAGER) {
                throw new \Exception('This refusal has already been decided.');
            }

            $note = filled($note) ? mb_substr(trim($note), 0, 255) : null;

            if ($approve) {
                (new RoomOrderService)->cancel($refusal->order, $manager, 'Refused delivery: '.$refusal->reason.($note ? " ({$note})" : ''));
            } else {
                GuestRequestItem::whereKey($refusal->line_ids)->update([
                    'delivery_status' => GuestRequestItem::DELIVERED,
                    'delivered_at' => now(),
                ]);
                $this->serveFinishedOrders(collect([$refusal->order_id]));
            }

            $refusal->update([
                'status' => $approve ? GuestDeliveryRefusal::APPROVED : GuestDeliveryRefusal::REJECTED,
                'decided_by_user_id' => $manager->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            return $refusal;
        });
    }

    /** Ready orders whose guest lines have all been delivered are served. */
    private function serveFinishedOrders(Collection $orderIds): void
    {
        foreach ($orderIds as $orderId) {
            $pending = GuestRequestItem::where('order_id', $orderId)
                ->whereNotIn('status', ['removed', 'cancelled'])
                ->where(fn ($q) => $q->whereNull('delivery_status')->orWhere('delivery_status', '!=', GuestRequestItem::DELIVERED))
                ->exists();

            if (! $pending) {
                Order::whereKey($orderId)->where('status', 'ready')->first()?->update(['status' => 'served', 'served_at' => now()]);
            }
        }
    }

    /**
     * @param  Collection<int, GuestRequestItem>|array<int, int>  $lines
     * @return Collection<int, GuestRequestItem>
     *
     * @throws \Exception
     */
    private function lock($lines, string $expected, string $label): Collection
    {
        $ids = collect($lines)->map(fn ($l) => $l instanceof GuestRequestItem ? $l->id : (int) $l)->unique()->values();

        if ($ids->isEmpty()) {
            throw new \Exception('Pick what to deliver.');
        }

        $locked = GuestRequestItem::whereKey($ids)->lockForUpdate()->get();

        if ($locked->count() !== $ids->count() || $locked->contains(fn ($l) => $l->delivery_status !== $expected)) {
            throw new \Exception("Something here isn't {$label} any more — refresh and try again.");
        }

        return $locked;
    }

    /**
     * @throws \Exception
     */
    private function assertRole(User $user, array $roles, string $message): void
    {
        if (! $user->hasRole($roles)) {
            throw new \Exception($message);
        }
    }

    private function alertManagers(Collection $refusals): void
    {
        $managers = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['manager', 'admin', 'super_admin']))->get();

        foreach ($refusals as $refusal) {
            $refusal->loadMissing('request.room');

            foreach ($managers as $manager) {
                Notification::make()
                    ->title('Room delivery refused — Room '.$refusal->request?->room?->number)
                    ->body($refusal->request?->ref.': '.$refusal->reason.($refusal->station === 'bar' ? ' (waiting for the bar to confirm the drinks came back)' : ''))
                    ->warning()
                    ->sendToDatabase($manager);
            }
        }
    }
}
