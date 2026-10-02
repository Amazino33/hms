<?php

namespace App\Services\Guest;

use App\Models\GuestRequest;
use App\Models\GuestRequestItem;
use App\Models\GuestTableSession;
use App\Models\GuestWaiterCall;
use App\Models\Order;
use App\Models\Table;
use App\Models\TableMove;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Moves a guest sitting to another table (Phase 4, D4) — the only place
 * guest code changes the table an order belongs to.
 *
 * In one transaction: the sitting's UNPAID bill orders (never paid ones)
 * move to the new table, as do its requests with lines still waiting, the
 * sitting itself, and its open waiter calls; a table_moves row records it.
 * The destination must be free: no unpaid order and no open sitting.
 */
class TableMoveService
{
    public const MANAGER_ROLES = ['manager', 'admin', 'super_admin'];

    /**
     * @throws \Exception with a message written for the waiter
     */
    public function move(GuestTableSession $session, Table $to, User $actor): TableMove
    {
        return DB::transaction(function () use ($session, $to, $actor) {
            $session = GuestTableSession::with('assignedWaiter')->lockForUpdate()->find($session->id);

            if (! $session?->isOpen()) {
                throw new \Exception('This guest table has already been closed.');
            }

            if ($session->table_id === $to->id) {
                throw new \Exception('The guests are already at '.$to->name.'.');
            }

            self::assertMayManage($session, $actor, 'move');

            // Both tables, in id order, so two moves can't deadlock.
            $tables = Table::whereKey([$session->table_id, $to->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $from = $tables[$session->table_id];
            $to = $tables[$to->id];

            if (! self::isFree($to)) {
                throw new \Exception($to->name.' is not free — it has an unpaid bill or guests ordering. Pick another table.');
            }

            $orderIds = GuestBillService::orders($session, withReturnTickets: true)
                ->whereIn('status', GuestBillService::UNPAID)
                ->pluck('id')->all();

            $requestIds = GuestRequest::where('guest_table_session_id', $session->id)
                ->where(fn ($q) => $q->where('status', GuestRequest::STATUS_PENDING)
                    ->orWhereHas('items', fn ($i) => $i->whereIn('status', GuestRequestItem::WAITING)))
                ->pluck('id')->all();

            if ($orderIds) {
                Order::whereKey($orderIds)->update(['table_id' => $to->id]);
            }

            if ($requestIds) {
                GuestRequest::whereKey($requestIds)->update(['table_id' => $to->id]);
            }

            GuestWaiterCall::where('guest_table_session_id', $session->id)
                ->where('status', GuestWaiterCall::STATUS_OPEN)
                ->update(['table_id' => $to->id]);

            $session->update(['table_id' => $to->id, 'last_activity_at' => now()]);

            $move = TableMove::create([
                'guest_table_session_id' => $session->id,
                'from_table_id' => $from->id,
                'to_table_id' => $to->id,
                'order_ids' => $orderIds,
                'request_ids' => $requestIds,
                'moved_by_user_id' => $actor->id,
            ]);

            // Keep the POS's stored table flag honest on both sides.
            if ($orderIds) {
                $to->update(['status' => 'occupied']);
            }

            if (! Order::where('table_id', $from->id)->whereIn('status', GuestBillService::UNPAID)->exists()) {
                $from->update(['status' => 'available']);
            }

            return $move;
        });
    }

    /** Tables a sitting may move to: no unpaid order, no open sitting. */
    public static function freeTables(?int $except = null)
    {
        return Table::query()
            ->when($except, fn ($q) => $q->whereKeyNot($except))
            ->whereDoesntHave('orders', fn ($q) => $q->whereIn('status', GuestBillService::UNPAID))
            ->whereNotIn('id', GuestTableSession::open()->select('table_id'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public static function isFree(Table $table): bool
    {
        return ! Order::where('table_id', $table->id)->whereIn('status', GuestBillService::UNPAID)->exists()
            && ! GuestTableSession::open()->where('table_id', $table->id)->exists();
    }

    /**
     * The sitting's waiter (by PIN) or a manager. A sitting no waiter has
     * taken yet may be handled by any waiter on shift.
     *
     * @throws \Exception
     */
    public static function assertMayManage(GuestTableSession $session, User $actor, string $verb): void
    {
        if ($actor->hasRole(self::MANAGER_ROLES)) {
            return;
        }

        $assigned = $session->assignedWaiter;

        if ($assigned && $assigned->id !== $actor->id) {
            throw new \Exception('This is '.GuestShifts::firstName($assigned).'\'s table — only they or a manager can '.$verb.' it.');
        }

        if (! $assigned && ! GuestShifts::activeWaiterShift($actor)) {
            throw new \Exception('Start your waiter shift first.');
        }
    }
}
