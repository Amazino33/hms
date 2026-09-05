<?php

namespace App\Services;

use App\Models\StaffDebt;
use App\Models\User;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The downloadable staff-debt ledger: every debt row a manager can filter
 * by business day, by any number of staff at once, by whether it is still
 * outstanding, and by where it came from.
 *
 * Distinct from Ceo\LeakageReportService, which only ever rolls debt up
 * per staff member for the executive panel. This one keeps the individual
 * debt rows — a staff member disputing a figure needs the line items, the
 * dates and the name of whoever raised each one, not a single total.
 *
 * Dates go through BusinessDay, not the calendar: a settlement shortfall
 * raised at 2am belongs to the night still being closed out, and a report
 * that said otherwise would disagree with every other owner-facing number
 * for the same day.
 */
class StaffDebtReportService
{
    /**
     * Debts the system raises on its own while a shift, a cashier session
     * or a stock handover count is being closed out — nobody typed these
     * in, they fell out of a reconciliation. Everything else was somebody's
     * deliberate entry, which is why the report separates the two.
     */
    public const HANDOVER_REASONS = [
        'shift_shortfall',
        'reception_shortfall',
        'cashier_session_shortfall',
        'count_session_shortfall',
    ];

    public const REASON_LABELS = [
        'shift_shortfall' => 'Shift settlement shortfall',
        'reception_shortfall' => 'Reception settlement shortfall',
        'cashier_session_shortfall' => 'Cashier session close-out gap',
        'count_session_shortfall' => 'Handover count shortage',
        'unpaid_order_conversion' => 'Unpaid order charged to staff',
        'manual' => 'Manually recorded',
    ];

    public const STATUS_LABELS = [
        'open' => 'Open',
        'partially_settled' => 'Partially settled',
        'settled' => 'Settled',
    ];

    public static function isHandoverReason(?string $reason): bool
    {
        return in_array($reason, self::HANDOVER_REASONS, true);
    }

    public static function reasonLabel(?string $reason): string
    {
        return self::REASON_LABELS[$reason] ?? ucfirst(str_replace('_', ' ', (string) $reason));
    }

    /**
     * One row per debt, newest first.
     *
     * @param  array{from?: ?string, to?: ?string, user_ids?: array<int|string>, status?: ?string, origin?: ?string}  $filters
     *                                                                                                                          from/to are business-day labels (Y-m-d); status is all|outstanding|open|
     *                                                                                                                          partially_settled|settled; origin is all|handover|recorded.
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(array $filters = []): Collection
    {
        $query = StaffDebt::query()
            ->with(['user:id,name', 'creator:id,name', 'shift:id,type,started_at,ended_at', 'order:id,order_number'])
            ->withSum('repayments as repaid_total', 'amount');

        [$start, $end] = self::window($filters['from'] ?? null, $filters['to'] ?? null);

        if ($start) {
            $query->where('created_at', '>=', $start);
        }

        if ($end) {
            $query->where('created_at', '<', $end);
        }

        $userIds = array_values(array_filter(array_map('intval', $filters['user_ids'] ?? [])));

        if ($userIds !== []) {
            $query->whereIn('user_id', $userIds);
        }

        $status = $filters['status'] ?? 'all';

        if ($status === 'outstanding') {
            $query->whereIn('status', ['open', 'partially_settled']);
        } elseif ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        $origin = $filters['origin'] ?? 'all';

        if ($origin === 'handover') {
            $query->whereIn('reason', self::HANDOVER_REASONS);
        } elseif ($origin === 'recorded') {
            $query->whereNotIn('reason', self::HANDOVER_REASONS);
        }

        return $query->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (StaffDebt $debt) => $this->row($debt));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(StaffDebt $debt): array
    {
        $amount = (float) $debt->amount;
        $repaid = (float) ($debt->repaid_total ?? 0);
        $handover = self::isHandoverReason($debt->reason);
        $shiftType = $debt->shift?->type;

        return [
            'id' => $debt->id,
            'business_day' => BusinessDay::labelFor($debt->created_at),
            'created_at' => $debt->created_at,
            'staff_id' => $debt->user_id,
            'staff_name' => $debt->user?->name ?? 'Unknown',
            'reason' => $debt->reason,
            'reason_label' => self::reasonLabel($debt->reason),
            'origin' => $handover ? 'handover' : 'recorded',
            // "During handover" is a claim about how the debt arose, so it
            // names the reconciliation it fell out of where one is known.
            'origin_label' => $handover
                ? 'During handover'.($shiftType ? " ({$shiftType} shift)" : '')
                : 'Recorded by a person',
            'shift_id' => $debt->shift_id,
            'shift_type' => $shiftType,
            'shift_started_at' => $debt->shift?->started_at,
            'order_number' => $debt->order?->order_number,
            'amount' => $amount,
            'repaid' => $repaid,
            'outstanding' => max(0, round($amount - $repaid, 2)),
            'status' => $debt->status,
            'status_label' => self::STATUS_LABELS[$debt->status] ?? (string) $debt->status,
            'recorded_by' => $debt->creator?->name ?? 'System',
            'age_days' => (int) $debt->created_at->diffInDays(CarbonImmutable::now()),
            'notes' => $debt->notes,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function summary(Collection $rows): array
    {
        $pending = $rows->filter(fn (array $r) => $r['outstanding'] > 0);

        return [
            'debts' => $rows->count(),
            'staff' => $rows->pluck('staff_id')->unique()->count(),
            'charged' => round((float) $rows->sum('amount'), 2),
            'repaid' => round((float) $rows->sum('repaid'), 2),
            'outstanding' => round((float) $rows->sum('outstanding'), 2),
            'pending_count' => $pending->count(),
            'settled_count' => $rows->where('status', 'settled')->count(),
            'handover_charged' => round((float) $rows->where('origin', 'handover')->sum('amount'), 2),
            'handover_outstanding' => round((float) $rows->where('origin', 'handover')->sum('outstanding'), 2),
            'recorded_charged' => round((float) $rows->where('origin', 'recorded')->sum('amount'), 2),
            'recorded_outstanding' => round((float) $rows->where('origin', 'recorded')->sum('outstanding'), 2),
            'oldest_pending_days' => (int) ($pending->max('age_days') ?? 0),
        ];
    }

    /**
     * Per-staff rollup of the same rows — what a manager reads first, and
     * what makes a multi-staff selection worth having.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    public function perStaff(Collection $rows): Collection
    {
        return $rows->groupBy('staff_id')
            ->map(function (Collection $staffRows) {
                $first = $staffRows->first();
                $pending = $staffRows->filter(fn (array $r) => $r['outstanding'] > 0);

                return [
                    'staff_id' => $first['staff_id'],
                    'staff_name' => $first['staff_name'],
                    'debts' => $staffRows->count(),
                    'charged' => round((float) $staffRows->sum('amount'), 2),
                    'repaid' => round((float) $staffRows->sum('repaid'), 2),
                    'outstanding' => round((float) $staffRows->sum('outstanding'), 2),
                    'pending_count' => $pending->count(),
                    'handover_outstanding' => round((float) $staffRows->where('origin', 'handover')->sum('outstanding'), 2),
                    'recorded_outstanding' => round((float) $staffRows->where('origin', 'recorded')->sum('outstanding'), 2),
                    'oldest_pending_days' => (int) ($pending->max('age_days') ?? 0),
                    'last_debt_on' => $staffRows->max('business_day'),
                ];
            })
            ->sortByDesc('outstanding')
            ->values();
    }

    /**
     * The staff picker only offers people who have actually been charged
     * something — on a full roster the useful names would otherwise be
     * buried among everyone who never owed a naira.
     *
     * @return Collection<int, User>
     */
    public function staffWithDebts(): Collection
    {
        return User::query()
            ->select(['id', 'name'])
            ->whereHas('debts')
            ->orderBy('name')
            ->get();
    }

    /**
     * The UTC instant range covering the requested business days — start
     * is the `from` day's 9am WAT, end is the morning after `to`.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    public static function window(?string $from, ?string $to): array
    {
        return [
            $from ? BusinessDay::boundsFor($from)[0] : null,
            $to ? BusinessDay::boundsFor($to)[1] : null,
        ];
    }
}
