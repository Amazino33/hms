<?php

namespace App\Services\Guest;

use App\Models\GuestRequest;
use App\Models\GuestTableSession;
use App\Models\Table;
use App\Models\TableMove;

/**
 * Where this phone stands at a scanned table (Phase 4):
 *
 *   moved   its sitting moved to another table — "scan the QR there"
 *   open    a sitting is open at this table
 *   closed  its last sitting here was closed (shown for 3 hours)
 *   none    nothing yet; the next order opens a sitting
 */
class GuestTableState
{
    public const CLOSED_NOTICE_HOURS = 3;

    /**
     * @return array{state: string, session: ?GuestTableSession, moved_to?: string, closed_ref?: string}
     */
    public static function for(Table $table, string $deviceId): array
    {
        $moved = TableMove::where('from_table_id', $table->id)
            ->whereHas('session', fn ($q) => $q->whereNull('closed_at')->where('table_id', '!=', $table->id)
                ->whereHas('requests', fn ($r) => $r->where('device_id', $deviceId)))
            ->with('session.table')
            ->latest('id')
            ->first();

        $open = GuestTableSession::open()->where('table_id', $table->id)->first();
        $mineHere = $open && GuestRequest::where('guest_table_session_id', $open->id)->where('device_id', $deviceId)->exists();

        // A phone that moved with its sitting sees the move, unless it has
        // already ordered in a new sitting back at this table.
        if ($moved && ! $mineHere) {
            return ['state' => 'moved', 'session' => null, 'moved_to' => (string) $moved->session->getRelationValue('table')?->name];
        }

        if ($open) {
            return ['state' => 'open', 'session' => $open];
        }

        $last = GuestRequest::where('table_id', $table->id)->where('device_id', $deviceId)
            ->whereNotNull('guest_table_session_id')
            ->latest('id')
            ->with('session')
            ->first()?->session;

        if ($last && ! $last->isOpen() && $last->closed_at->gt(now()->subHours(self::CLOSED_NOTICE_HOURS))) {
            return ['state' => 'closed', 'session' => null, 'closed_ref' => $last->public_id];
        }

        return ['state' => 'none', 'session' => null];
    }

    /** @return array<string, mixed> what the page needs, without the model */
    public static function present(array $state): array
    {
        return array_filter([
            'state' => $state['state'],
            'moved_to' => $state['moved_to'] ?? null,
            'closed_ref' => $state['closed_ref'] ?? null,
        ], fn ($v) => $v !== null);
    }
}
