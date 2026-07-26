<?php

namespace App\Services\Ceo;

use App\Models\Booking;
use App\Models\BookingRoomSupplyUsage;
use App\Services\SettingsService;

/**
 * Room profit = revenue (OccupancyReportService's existing per-night
 * nightly_rate convention, so this never disagrees with the occupancy/ADR
 * figures elsewhere) minus a flat per-night power-cost estimate minus
 * whatever room supplies were actually recorded as used. Deliberately
 * reuses OccupancyReportService rather than re-deriving room-nights/
 * revenue itself.
 */
class RoomProfitService
{
    public function __construct(private readonly OccupancyReportService $occupancy = new OccupancyReportService())
    {
    }

    public function powerCostPerNight(): float
    {
        return (float) SettingsService::get('room_generator_cost_per_night', '0')
            + (float) SettingsService::get('room_electricity_cost_per_night', '0');
    }

    /**
     * @return array{nights: int, revenue: float, power_cost: float, supplies_cost: float, total_cost: float, profit: float}
     */
    public function forBooking(Booking $booking): array
    {
        $nights = $booking->nights();
        $revenue = (float) ($booking->nightly_rate ?? 0) * $nights;
        $powerCost = $this->powerCostPerNight() * $nights;
        $suppliesCost = $this->suppliesCostForBooking($booking);
        $totalCost = $powerCost + $suppliesCost;

        return [
            'nights' => $nights,
            'revenue' => $revenue,
            'power_cost' => $powerCost,
            'supplies_cost' => $suppliesCost,
            'total_cost' => $totalCost,
            'profit' => $revenue - $totalCost,
        ];
    }

    public function suppliesCostForBooking(Booking $booking): float
    {
        return (float) $booking->roomSupplyUsages()
            ->get()
            ->sum(fn (BookingRoomSupplyUsage $usage) => $usage->totalCost());
    }

    /**
     * @return array{revenue: float, power_cost: float, supplies_cost: float, total_cost: float, profit: float, room_nights_sold: int}
     */
    public function summary(DateRange $range): array
    {
        $occSummary = $this->occupancy->summary($range);
        $revenue = (float) $occSummary['total_room_revenue'];
        $roomNightsSold = (int) $occSummary['room_nights_sold'];
        $powerCost = $this->powerCostPerNight() * $roomNightsSold;

        $suppliesCost = (float) BookingRoomSupplyUsage::whereBetween('created_at', [$range->startBoundary(), $range->endBoundary()])
            ->get()
            ->sum(fn (BookingRoomSupplyUsage $usage) => $usage->totalCost());

        $totalCost = $powerCost + $suppliesCost;

        return [
            'revenue' => $revenue,
            'power_cost' => $powerCost,
            'supplies_cost' => $suppliesCost,
            'total_cost' => $totalCost,
            'profit' => $revenue - $totalCost,
            'room_nights_sold' => $roomNightsSold,
        ];
    }
}
