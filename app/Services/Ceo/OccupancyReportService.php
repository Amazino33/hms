<?php

namespace App\Services\Ceo;

use App\Models\Booking;
use App\Models\Room;
use Illuminate\Support\Collection;

/**
 * Nights-based occupancy — the single source of truth every other CEO
 * report (Sales Report's Rooms bucket, the dashboard KPI strip) defers to
 * for "how many rooms were occupied, and for how much" on any given
 * calendar night. A room only counts as occupied for a night if a guest
 * was actually in-house that night (status checked_in/checked_out — never
 * reserved/cancelled/no_show, mirroring Room::occupancyState()'s own
 * status-aware rule); a morning checkout does not count for that night,
 * because check_out is compared with a strict "<" below.
 *
 * Room revenue per night is the booking's frozen nightly_rate, not a share
 * of the single lump-sum room_charge FolioLine posted at check-in — the
 * folio has no per-night breakdown, and nightly_rate is exactly what was
 * designed to fill that role.
 */
class OccupancyReportService
{
    public function nightlyBreakdown(DateRange $range, ?int $roomId = null): Collection
    {
        $totalRooms = $roomId ? 1 : max(1, Room::count());

        $bookings = Booking::query()
            ->whereIn('status', ['checked_in', 'checked_out'])
            ->when($roomId, fn ($q) => $q->where('room_id', $roomId))
            ->where('check_in', '<', $range->end->addDay()->toDateString())
            ->where('check_out', '>', $range->start->toDateString())
            ->get();

        return collect($range->eachDate())->map(function ($date) use ($bookings, $totalRooms) {
            $dateStr = $date->toDateString();

            $occupied = $bookings->filter(
                fn (Booking $b) => $b->check_in->toDateString() <= $dateStr && $b->check_out->toDateString() > $dateStr
            );

            $roomsOccupied = $occupied->count();

            return [
                'date' => $date,
                'rooms_occupied' => $roomsOccupied,
                'occupancy_pct' => round($roomsOccupied / $totalRooms * 100, 2),
                'room_revenue' => (float) $occupied->sum(fn (Booking $b) => (float) ($b->nightly_rate ?? 0)),
                'arrivals' => $bookings->filter(fn (Booking $b) => $b->checked_in_at?->toDateString() === $dateStr)->count(),
                'departures' => $bookings->filter(fn (Booking $b) => $b->checked_out_at?->toDateString() === $dateStr)->count(),
            ];
        });
    }

    public function summary(DateRange $range, ?int $roomId = null): array
    {
        $days = $this->nightlyBreakdown($range, $roomId);
        $totalRooms = $roomId ? 1 : max(1, Room::count());
        $roomNightsAvailable = $totalRooms * $range->days();
        $roomNightsSold = (int) $days->sum('rooms_occupied');
        $roomRevenue = (float) $days->sum('room_revenue');

        return [
            'average_occupancy_pct' => $days->isNotEmpty() ? round((float) $days->avg('occupancy_pct'), 2) : 0.0,
            'total_room_revenue' => $roomRevenue,
            'adr' => $roomNightsSold > 0 ? round($roomRevenue / $roomNightsSold, 2) : 0.0,
            'revpar' => $roomNightsAvailable > 0 ? round($roomRevenue / $roomNightsAvailable, 2) : 0.0,
            'room_nights_sold' => $roomNightsSold,
            'room_nights_available' => $roomNightsAvailable,
            'day_of_week_averages' => $this->dayOfWeekAverages($days),
        ];
    }

    /**
     * @return array<string, float> "Monday".."Sunday" => average occupancy %
     */
    private function dayOfWeekAverages(Collection $days): array
    {
        return $days->groupBy(fn ($d) => $d['date']->format('l'))
            ->map(fn ($group) => round((float) $group->avg('occupancy_pct'), 2))
            ->all();
    }
}
