<?php

namespace App\Services;

use App\Filament\Pages\CountSessions;
use App\Models\CountBreakdown;
use App\Models\CountBreakdownLine;
use App\Models\CountBreakdownMovement;
use App\Models\CountSession;
use App\Models\HandoverDiscrepancy;
use App\Models\StaffDebt;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Read side of the count breakdown: who may see it, and the one payload
 * every surface renders from (staff summary, admin review, CEO page, PDF,
 * CSV). Reads the frozen snapshot only; the only live reads are the
 * manager's ruling and any debt booked from it, which are shown as they
 * stand now.
 *
 * Cost price is only ever added for an audit viewer, so a staff payload
 * cannot leak it into the page source.
 */
class CountBreakdownViewService
{
    public const SECTION_LABELS = ['product' => 'Products', 'ingredient' => 'Ingredients'];

    public const TYPE_LABELS = [
        'bar_handover' => 'Bar handover',
        'kitchen_handover' => 'Kitchen handover',
        'main_store_stocktake' => 'Store count',
    ];

    /**
     * Admin, manager, super-admin: whoever can open the Count Sessions
     * list can audit any count's breakdown. The CEO reads the same audit
     * view (read-only) from the /ceo panel.
     */
    public static function canAudit(): bool
    {
        return PermissionService::canAccessPage(CountSessions::class)
            || (bool) auth()->user()?->hasRole('ceo');
    }

    /**
     * Sealed (finalized, on the review path) counts only: before that the
     * figures are still the blind count's secret.
     */
    public static function canView(CountSession $session, ?User $user): bool
    {
        if (! $user || ! $session->isReviewed()) {
            return false;
        }

        return self::canAudit() || $session->isParticipant($user->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(CountSession $session, bool $audit, ?User $viewer = null): array
    {
        $breakdown = CountBreakdown::query()
            ->with(['previousSession'])
            ->where('count_session_id', $session->id)
            ->first();

        $session->loadMissing(['warehouse']);

        $base = [
            'session_id' => $session->id,
            'title' => self::TYPE_LABELS[$session->type] ?? $session->type,
            'warehouse' => $session->warehouse?->name,
            'sealed_at' => $this->time($session->reviewed_at),
            'audit' => $audit,
            'recorded' => $breakdown !== null,
        ];

        if (! $breakdown) {
            return $base;
        }

        $lines = $breakdown->currentLines()
            ->with(['movements', 'notes', 'sessionItem.discrepancy.staffDebt.user', 'sessionItem.discrepancy.resolvedBy'])
            ->orderBy('item_name')
            ->get();

        $slowMinutes = CountBreakdownSettings::slowReleaseMinutes();
        $repeat = $audit ? $this->repeatShortages($breakdown, $lines) : [];
        $notes = new CountVarianceNoteService;
        $canNote = $viewer && $session->isParticipant($viewer->id);

        $sections = $lines->groupBy('section')->map(function (Collection $sectionLines, string $section) use ($audit, $slowMinutes, $repeat, $notes, $canNote, $session) {
            $rows = $sectionLines->map(fn (CountBreakdownLine $line) => $this->line($line, $audit, $slowMinutes, $repeat, $canNote && $notes->isOpenForNotes($line), $session));

            return [
                'key' => $section,
                'label' => self::SECTION_LABELS[$section] ?? ucfirst($section),
                'lines' => $rows->values()->all(),
                'totals' => $this->totals($sectionLines, $audit),
            ];
        });

        return $base + [
            'reconstructed_at' => $this->time($breakdown->reconstructed_at),
            'window_from' => $this->time($breakdown->window_from),
            'window_to' => $this->time($breakdown->window_to),
            'counted_by' => $breakdown->counted_by_name,
            'counted_at' => $this->time($breakdown->counted_at),
            'signers' => collect([
                [$breakdown->first_signer_label, $breakdown->first_signer_name],
                [$breakdown->second_signer_label, $breakdown->second_signer_name],
            ])->filter(fn ($s) => $s[1])->map(fn ($s) => ['label' => $s[0], 'name' => $s[1]])->values()->all(),
            'previous' => $breakdown->previousSession ? [
                'id' => $breakdown->previousSession->id,
                'sealed_at' => $this->time($breakdown->previousSession->reviewed_at),
                'url' => "/admin/count-session-detail?session_id={$breakdown->previous_count_session_id}",
            ] : null,
            'slow_minutes' => $slowMinutes,
            'sections' => collect(self::SECTION_LABELS)->keys()->filter(fn ($k) => $sections->has($k))->map(fn ($k) => $sections[$k])->values()->all(),
            'open_orders' => $session->openOrdersAtHandover()->orderBy('placed_at')->get()->map(fn ($o) => [
                'order' => $o->order_number ?? "#{$o->order_id}",
                'item' => $o->item_name,
                'quantity' => (float) $o->quantity,
                'waiter' => $o->waiter_name,
                'placed_at' => $this->time($o->placed_at),
                'status' => $o->order_status,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function line(CountBreakdownLine $line, bool $audit, int $slowMinutes, array $repeat, bool $canNote, CountSession $session): array
    {
        $movements = $line->movements->groupBy('figure')->map(
            fn (Collection $group) => $group->map(fn (CountBreakdownMovement $m) => $this->movement($m, $slowMinutes))->values()->all()
        );

        $soldActive = $line->movements->where('figure', 'sold')->where('status', 'active');
        $slowCount = collect($movements['sold'] ?? [])->where('slow', true)->where('status', 'active')->count();
        $variance = (float) $line->variance_qty;

        $row = [
            'id' => $line->id,
            'name' => $line->item_name,
            'unit' => $line->unit,
            'pack' => $line->units_per_pack ? ['name' => $line->pack_unit_name ?: 'pack', 'size' => $line->units_per_pack] : null,
            'brought_forward' => (float) $line->brought_forward,
            'brought_forward_note' => $line->brought_forward_note,
            'transferred' => (float) $line->transferred_in,
            'returns' => (float) $line->returns_in,
            'other_in' => (float) $line->other_in,
            'available' => (float) $line->available,
            'sold' => (float) $line->sold_qty,
            'sales_amount' => $line->sales_amount === null ? null : (float) $line->sales_amount,
            'damages' => (float) $line->damages_writeoffs,
            'other_out' => (float) $line->other_out,
            'unrecorded' => (float) $line->unrecorded_change,
            'expected' => (float) $line->expected_remaining,
            'counted' => (float) $line->counted,
            'variance' => $variance,
            'unit_price' => (float) $line->unit_selling_price,
            'value' => (float) $line->variance_value_selling,
            'quiet' => ! $line->has_movement && abs($variance) < 0.0001,
            'movements' => $movements->all(),
            'waiters' => $soldActive->groupBy(fn ($m) => $m->waiter_name ?: 'Unassigned')
                ->map(fn ($g, $name) => ['name' => $name, 'quantity' => (float) $g->sum('quantity')])
                ->sortByDesc('quantity')->values()->all(),
            'slow_count' => $audit ? $slowCount : null,
            'notes' => $line->notes->sortBy('id')->map(fn ($n) => $this->note($n))->values()->all(),
            'can_note' => $canNote,
            'ruling' => $this->ruling($line, $session),
        ];

        if ($audit) {
            $row['unit_cost'] = $line->unit_cost_price === null ? null : (float) $line->unit_cost_price;
            $row['value_cost'] = $line->variance_value_cost === null ? null : (float) $line->variance_value_cost;
            $row['repeat'] = $repeat[$line->section.':'.$line->item_id] ?? null;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function movement(CountBreakdownMovement $m, int $slowMinutes): array
    {
        $minutes = ($m->placed_at && $m->ready_at) ? (int) floor($m->placed_at->diffInSeconds($m->ready_at) / 60) : null;

        return [
            'quantity' => (float) $m->quantity,
            'amount' => $m->amount === null ? null : (float) $m->amount,
            'status' => $m->status,
            'label' => $m->label,
            'source_id' => $m->source_id,
            'order' => $m->meta['order_number'] ?? ($m->order_id ? "#{$m->order_id}" : null),
            'transfer' => $m->meta['transfer_number'] ?? null,
            'waiter' => $m->waiter_name,
            'sender' => $m->sender_name,
            'receiver' => $m->receiver_name,
            'recorder' => $m->recorder_name,
            'approver' => $m->approver_name,
            'voided_by' => $m->voided_by_name,
            'reason' => $m->reason,
            'placed_at' => $this->time($m->placed_at),
            'ready_at' => $this->time($m->ready_at),
            'sent_at' => $this->time($m->sent_at),
            'received_at' => $this->time($m->received_at),
            'recorded_at' => $this->time($m->recorded_at),
            'voided_at' => $this->time($m->voided_at),
            'ready_minutes' => $minutes,
            'slow' => $minutes !== null && $minutes > $slowMinutes,
            'dishes' => $m->meta['dishes'] ?? [],
            'comp' => $m->meta['comp'] ?? null,
            'price_estimated' => (bool) ($m->meta['price_estimated'] ?? false),
        ];
    }

    public function note($note): array
    {
        return [
            'author' => $note->author_name,
            'body' => $note->body,
            'at' => $this->time($note->created_at),
        ];
    }

    /**
     * The manager's ruling and any debt booked from it, read live.
     * Shortages on the dual-PIN path link through their discrepancy line;
     * a closing count's manager review books the debt directly, so that
     * debt is found by the reference its notes carry.
     */
    private function ruling(CountBreakdownLine $line, CountSession $session): ?array
    {
        $discrepancy = $line->sessionItem?->discrepancy;

        if ($discrepancy instanceof HandoverDiscrepancy) {
            return [
                'status' => $discrepancy->status,
                'label' => match ($discrepancy->status) {
                    'pending_resolution' => 'Waiting for manager ruling',
                    'pending_investigation' => 'Under investigation',
                    'debited' => 'Charged to staff',
                    'written_off' => 'Written off',
                    'acknowledged' => 'Acknowledged',
                    default => $discrepancy->status,
                },
                'note' => $discrepancy->resolution_note ?? $discrepancy->investigation_note,
                'by' => $discrepancy->resolvedBy?->name,
                'at' => $this->time($discrepancy->resolved_at),
                'debt' => $discrepancy->staffDebt ? [
                    'name' => $discrepancy->staffDebt->user?->name,
                    'amount' => (float) $discrepancy->staffDebt->amount,
                ] : null,
            ];
        }

        $item = $line->sessionItem;

        if (! $item || ! $item->decision) {
            return null;
        }

        $debt = $item->decision === 'accountability'
            ? StaffDebt::query()->with('user')
                ->where('reason', 'count_session_shortfall')
                ->where('notes', 'like', "Count session #{$session->id}, item #{$item->id} (%")
                ->first()
            : null;

        return [
            'status' => $item->decision,
            'label' => match ($item->decision) {
                'accountability' => 'Charged to staff',
                'true_up' => 'Stock corrected, no charge',
                'ignored' => 'Ignored',
                default => $item->decision,
            },
            'note' => $item->decision_notes,
            'by' => null,
            'at' => null,
            'debt' => $debt ? ['name' => $debt->user?->name, 'amount' => (float) $debt->amount] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function totals(Collection $lines, bool $audit): array
    {
        $totals = [
            'sales' => round((float) $lines->sum(fn ($l) => (float) $l->sales_amount), 2),
            'variance_items' => $lines->filter(fn ($l) => $l->hasVariance())->count(),
            'shortage_value' => round((float) $lines->filter(fn ($l) => (float) $l->variance_qty < 0)->sum(fn ($l) => abs((float) $l->variance_value_selling)), 2),
            'variance_value' => round((float) $lines->sum(fn ($l) => (float) $l->variance_value_selling), 2),
        ];

        if ($audit) {
            $totals['variance_value_cost'] = round((float) $lines->sum(fn ($l) => (float) $l->variance_value_cost), 2);
            $totals['shortage_value_cost'] = round((float) $lines->filter(fn ($l) => (float) $l->variance_qty < 0)->sum(fn ($l) => abs((float) $l->variance_value_cost)), 2);
        }

        return $totals;
    }

    /**
     * "Short N of last M": over the last M recorded counts at this location
     * up to and including this one, how many found each item short.
     * Computed from stored breakdown lines only.
     *
     * @return array<string, array{short: int, of: int}>
     */
    public function repeatShortages(CountBreakdown $breakdown, Collection $lines): array
    {
        $window = CountBreakdownSettings::repeatShortageWindow();
        $threshold = CountBreakdownSettings::repeatShortageThreshold();
        $warehouseId = $lines->first()?->warehouse_id;

        if (! $warehouseId) {
            return [];
        }

        $breakdownIds = CountBreakdown::query()
            ->whereHas('session', fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->where('window_to', '<=', $breakdown->window_to)
            ->orderByDesc('window_to')
            ->orderByDesc('id')
            ->limit($window)
            ->pluck('id');

        $history = CountBreakdownLine::query()
            ->whereIn('count_breakdown_id', $breakdownIds)
            ->whereNull('supersedes_id')
            ->get(['section', 'item_id', 'variance_qty'])
            ->groupBy(fn ($l) => $l->section.':'.$l->item_id);

        $result = [];

        foreach ($history as $key => $group) {
            $short = $group->filter(fn ($l) => (float) $l->variance_qty < -0.0001)->count();

            if ($short >= $threshold) {
                $result[$key] = ['short' => $short, 'of' => $breakdownIds->count()];
            }
        }

        return $result;
    }

    /**
     * One item's line from every recorded count, oldest first.
     */
    public function trace(string $section, int $itemId, ?int $warehouseId = null): Collection
    {
        $lines = CountBreakdownLine::query()
            ->with(['session.warehouse', 'session.outgoingUser', 'session.incomingUser', 'breakdown'])
            ->where('section', $section)
            ->where('item_id', $itemId)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->whereNotIn('id', fn ($q) => $q->select('supersedes_id')->from('count_breakdown_lines')->whereNotNull('supersedes_id'))
            ->get()
            ->sortBy(fn ($l) => $l->breakdown?->window_to)
            ->values();

        $threshold = CountBreakdownSettings::repeatShortageThreshold();
        $window = CountBreakdownSettings::repeatShortageWindow();

        return $lines->map(function (CountBreakdownLine $line, int $index) use ($lines, $threshold, $window) {
            $recent = $lines->take($index + 1)
                ->where('warehouse_id', $line->warehouse_id)
                ->slice(-$window);
            $short = $recent->filter(fn ($l) => (float) $l->variance_qty < -0.0001)->count();

            return [
                'line' => $line,
                'session' => $line->session,
                'sealed_at' => $this->time($line->breakdown?->window_to),
                'repeat' => $short >= $threshold ? ['short' => $short, 'of' => $recent->count()] : null,
            ];
        });
    }

    private function time(?CarbonInterface $instant): ?string
    {
        return $instant?->venueTime()->format('M j, g:i A');
    }
}
