<?php

namespace App\Services\Attendance;

use App\Models\Attendance\AttendanceSetting;
use App\Models\AttendanceLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Decides which shift each of a person's punches belongs to.
 *
 * Windows overlap by design — 2 hours before and 4 hours after means a 24h
 * bartender rota has every punch sitting inside two consecutive windows. Left
 * alone, the same punch would be read as yesterday's clock-out AND today's
 * clock-in, so one person could be simultaneously present and absent.
 *
 * So assignment happens once, for all of a person's shifts together, and each
 * punch lands in exactly one.
 */
class PunchWindowAssigner
{
    /**
     * @param  Collection<int, ExpectedShift>  $shifts
     * @param  Collection<int, AttendanceLog>  $punches
     * @return array{assigned: array<int, Collection<int, AttendanceLog>>, unassigned: Collection<int, AttendanceLog>}
     */
    public function assign(Collection $shifts, Collection $punches, AttendanceSetting $settings): array
    {
        $windows = $shifts->values()->map(function (ExpectedShift $shift) use ($settings) {
            [$start, $end] = ShiftEvaluator::windowFor($shift, $settings);

            return ['shift' => $shift, 'start' => $start, 'end' => $end];
        });

        $assigned = [];
        $unassigned = collect();

        foreach ($punches as $punch) {
            $at = CarbonImmutable::instance($punch->punch_time->toDateTime());

            // Original keys preserved: they are the shift indexes the result
            // is keyed by, and re-indexing here would assign punches to the
            // wrong shifts entirely.
            $candidates = $windows->filter(
                fn (array $w) => $at->greaterThanOrEqualTo($w['start']) && $at->lessThanOrEqualTo($w['end'])
            );

            if ($candidates->isEmpty()) {
                // A punch belonging to no shift at all. Not a fine — usually a
                // rota that is wrong, which is a review item.
                $unassigned->push($punch);

                continue;
            }

            $index = $this->pick($candidates, $at);

            $assigned[$index][] = $punch;
        }

        $out = [];

        foreach ($windows as $i => $window) {
            $out[$i] = collect($assigned[$i] ?? [])
                ->sortBy(fn (AttendanceLog $p) => $p->punch_time->getTimestamp())
                ->values();
        }

        return ['assigned' => $out, 'unassigned' => $unassigned];
    }

    /**
     * When several windows contain a punch, it belongs to the shift whose own
     * boundary it sits nearest — the earlier shift's END, or the later
     * shift's START. A punch at 07:55 is plainly the 08:00 start rather than
     * a very late clock-out from a shift that ended hours ago.
     *
     * Ties go to the later shift: at the exact midpoint between two shifts, a
     * punch is far more likely to be somebody arriving than somebody leaving
     * who is hours overdue.
     *
     * @param  Collection<int, array{shift: ExpectedShift, start: CarbonImmutable, end: CarbonImmutable}>  $candidates
     */
    private function pick(Collection $candidates, CarbonImmutable $at): int
    {
        $bestIndex = null;
        $bestDistance = null;

        foreach ($candidates as $index => $candidate) {
            $shift = $candidate['shift'];

            $distance = min(
                abs($at->getTimestamp() - $shift->startsAt->getTimestamp()),
                abs($at->getTimestamp() - $shift->endsAt->getTimestamp()),
            );

            // <= rather than < so an exact tie keeps the LATER shift: the
            // candidates arrive in chronological order, so the last equal
            // match wins.
            if ($bestDistance === null || $distance <= $bestDistance) {
                $bestDistance = $distance;
                $bestIndex = $index;
            }
        }

        return $bestIndex;
    }
}
