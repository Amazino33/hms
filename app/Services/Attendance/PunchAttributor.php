<?php

namespace App\Services\Attendance;

use App\Models\Attendance\AttendanceDeviceLink;
use App\Models\AttendanceLog;
use App\Support\VenueTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Works out who a punch actually belongs to.
 *
 * Deliberately NOT attendance_logs.user_id. That column is written at
 * ingestion from whatever pairing existed at that moment, so it is wrong in
 * exactly the cases that matter: punches that arrived before anyone paired the
 * badge (the terminal buffers offline and dumps a backlog on reconnect), and
 * punches claimed by a link later found to be a mistake.
 *
 * The link history is the authority. A punch belongs to whoever held that
 * badge on the punch's own Lagos date, according to a link that has not been
 * voided — and a voided link counts for nothing, because voiding is an
 * assertion the link never should have existed.
 *
 * Read-only. This never writes.
 */
class PunchAttributor
{
    /**
     * Link rows indexed by device id, loaded once per batch.
     *
     * @var array<string, array<int, AttendanceDeviceLink>>|null
     */
    private ?array $linksByDevice = null;

    /**
     * Load every non-voided link once. The alternative is a query per punch,
     * and a month of simulation is tens of thousands of punches.
     */
    public function preload(): void
    {
        $this->linksByDevice = AttendanceDeviceLink::query()
            ->notVoided()
            ->with('deviceUser')
            ->get()
            ->groupBy(fn (AttendanceDeviceLink $link) => (string) $link->deviceUser?->device_user_id)
            ->map(fn (Collection $links) => $links->sortBy('effective_from')->values()->all())
            ->all();
    }

    public function flush(): void
    {
        $this->linksByDevice = null;
    }

    /**
     * Who owned this badge on this date, or null if nobody did.
     */
    public function userIdFor(?string $biometricId, CarbonInterface $punchedAt): ?int
    {
        return $this->linkFor($biometricId, $punchedAt)?->user_id;
    }

    public function linkFor(?string $biometricId, CarbonInterface $punchedAt): ?AttendanceDeviceLink
    {
        if ($biometricId === null || $biometricId === '') {
            return null;
        }

        if ($this->linksByDevice === null) {
            $this->preload();
        }

        $date = $this->localDate($punchedAt);

        foreach ($this->linksByDevice[$biometricId] ?? [] as $link) {
            if ($this->covers($link, $date)) {
                return $link;
            }
        }

        return null;
    }

    /**
     * Group a set of punches by the user who owns them.
     *
     * Punches whose badge was linked to nobody on that date are dropped: they
     * belong to a person the system cannot name, which is a device-linking
     * problem rather than an attendance one, and the unmatched device-users
     * screen is where it gets fixed.
     *
     * @param  iterable<AttendanceLog>  $punches
     * @return Collection<int, Collection<int, AttendanceLog>>
     */
    public function groupByOwner(iterable $punches): Collection
    {
        $byUser = [];

        foreach ($punches as $punch) {
            $userId = $this->userIdFor($punch->biometric_id, $punch->punch_time);

            if ($userId === null) {
                continue;
            }

            $byUser[$userId][] = $punch;
        }

        return collect($byUser)->map(
            fn (array $rows) => collect($rows)->sortBy(fn (AttendanceLog $p) => $p->punch_time->getTimestamp())->values()
        );
    }

    /**
     * Whether this person had any badge at all on a date — the difference
     * between "absent" and "we were never able to see them".
     */
    public function hasLinkOn(int $userId, CarbonInterface $date): bool
    {
        if ($this->linksByDevice === null) {
            $this->preload();
        }

        $day = $this->localDate($date);

        foreach ($this->linksByDevice ?? [] as $links) {
            foreach ($links as $link) {
                if ($link->user_id === $userId && $this->covers($link, $day)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A link covers a date from effective_from, inclusive, to effective_to,
     * inclusive — or forever if it is still open.
     *
     * Retirement of the device user is deliberately not consulted: a badge
     * retired last month still legitimately owns the punches made before it
     * was retired, and those are exactly the shifts a back-test judges.
     */
    private function covers(AttendanceDeviceLink $link, CarbonImmutable $date): bool
    {
        $from = $this->dateOf($link->effective_from);

        if ($date->lessThan($from)) {
            return false;
        }

        if ($link->effective_to === null) {
            return true;
        }

        return $date->lessThanOrEqualTo($this->dateOf($link->effective_to));
    }

    private function dateOf(mixed $value): CarbonImmutable
    {
        return CarbonImmutable::parse(
            $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) $value,
            VenueTime::TIMEZONE,
        )->startOfDay();
    }

    /**
     * The Lagos date a punch instant falls on. Punches are stored UTC, and
     * a 00:30 Lagos punch is 23:30 UTC the day before — reading the UTC date
     * would attribute it to the previous link.
     */
    private function localDate(CarbonInterface $instant): CarbonImmutable
    {
        return CarbonImmutable::instance($instant->toDateTime())
            ->setTimezone(VenueTime::TIMEZONE)
            ->startOfDay();
    }
}
