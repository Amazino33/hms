<?php

namespace App\Filament\Ceo\Pages;

use App\Filament\Ceo\Concerns\ExportsCeoReports;
use App\Models\Room;
use App\Services\Ceo\DateRange;
use App\Services\Ceo\OccupancyReportService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;

class OccupancyReport extends Page
{
    use ExportsCeoReports;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $title = 'Occupancy Report';

    protected string $view = 'filament-ceo.pages.occupancy-report';

    public string $dateFrom;

    public string $dateTo;

    public ?int $roomId = null;

    public function mount(): void
    {
        $this->dateFrom = CarbonImmutable::today()->startOfMonth()->toDateString();
        $this->dateTo = CarbonImmutable::today()->toDateString();
    }

    public function range(): DateRange
    {
        return new DateRange(CarbonImmutable::parse($this->dateFrom), CarbonImmutable::parse($this->dateTo));
    }

    public function rooms()
    {
        return Room::orderBy('number')->get();
    }

    public function summary(): array
    {
        return (new OccupancyReportService())->summary($this->range(), $this->roomId);
    }

    public function dailyRows()
    {
        return (new OccupancyReportService())->nightlyBreakdown($this->range(), $this->roomId);
    }

    public function exportCsv()
    {
        return $this->csvResponse('occupancy-report.csv', [
            'Date', 'Rooms Occupied', 'Occupancy %', 'Room Revenue', 'Arrivals', 'Departures',
        ], $this->dailyRows()->map(fn ($r) => [
            $r['date']->toDateString(), $r['rooms_occupied'], $r['occupancy_pct'], $r['room_revenue'], $r['arrivals'], $r['departures'],
        ]));
    }

    public function exportPdf()
    {
        $summary = $this->summary();

        return $this->pdfResponse('occupancy-report.pdf', 'Occupancy Report', $this->filtersDescription(), [
            'Average occupancy %' => number_format($summary['average_occupancy_pct'], 2) . '%',
            'Total room revenue' => '₦' . number_format($summary['total_room_revenue'], 2),
            'ADR' => '₦' . number_format($summary['adr'], 2),
            'RevPAR' => '₦' . number_format($summary['revpar'], 2),
        ], [
            'Date', 'Rooms Occupied', 'Occupancy %', 'Room Revenue', 'Arrivals', 'Departures',
        ], $this->dailyRows()->map(fn ($r) => [
            $r['date']->format('Y-m-d'), $r['rooms_occupied'], number_format($r['occupancy_pct'], 2),
            number_format($r['room_revenue'], 2), $r['arrivals'], $r['departures'],
        ]));
    }

    private function filtersDescription(): string
    {
        $room = $this->roomId ? Room::find($this->roomId)?->number : 'All Rooms';

        return "Room: {$room} | {$this->dateFrom} to {$this->dateTo}";
    }
}
