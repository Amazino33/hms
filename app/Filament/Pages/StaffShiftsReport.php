<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use App\Models\Shift;
use App\Models\User;
use App\Services\PermissionService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Carbon\Carbon;
use BackedEnum;

class StaffShiftsReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-clock';
    protected static ?string $navigationLabel = 'Staff Shifts Report';
    protected static ?int $navigationSort = 3;
    protected string $view = 'filament.pages.staff-shifts-report';

    // Only admins can access this
    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    // Defer heavy report generation until client-side init
    public bool $ready = false;

    public ?array $data = [];

    public function load(): void
    {
        $this->ready = true;
    }

    public function mount(): void
    {
        $this->form->fill([
            'date' => now()->format('Y-m-d'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                DatePicker::make('date')
                    ->label('Select Date')
                    ->default(now())
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn () => $this->refreshData()),
            ])->statePath('data');
    }

    public function refreshData()
    {
        // This will trigger a re-render
    }

    protected function getViewData(): array
    {
        // If the component is not ready, return lightweight placeholders so
        // the expensive Shift query doesn't run during initial page render.
        if (! $this->ready) {
            $date = $this->data['date'] ?? now()->format('Y-m-d');

            return [
                'reportDate' => Carbon::parse($date)->format('l, d M Y'),
                'staffShifts' => collect(),
                'totalStaff' => 0,
                'totalShifts' => 0,
                'activeShifts' => 0,
                'completedShifts' => 0,
                'totalPayments' => 0,
            ];
        }

        $date = $this->data['date'] ?? now()->format('Y-m-d');
        $targetDate = Carbon::parse($date);

        // Get all shifts for the selected date
        $shifts = Shift::whereDate('started_at', $targetDate)
            ->with(['user', 'payments'])
            ->orderBy('started_at')
            ->get();

        // Group shifts by user
        $staffShifts = $shifts->groupBy('user_id')->map(function ($userShifts, $userId) {
            $user = $userShifts->first()->user;
            return [
                'user' => $user,
                'shifts' => $userShifts->map(function ($shift) {
                    return [
                        'id' => $shift->id,
                        'started_at' => $shift->started_at,
                        'ended_at' => $shift->ended_at,
                        'duration' => $shift->ended_at ? $shift->started_at->diffInMinutes($shift->ended_at) : null,
                        'is_active' => is_null($shift->ended_at),
                        'total_payments' => $shift->payments->sum('amount'),
                        'transaction_count' => $shift->payments->count(),
                        'cash_payments' => $shift->payments->where('method', 'cash')->sum('amount'),
                        'pos_payments' => $shift->payments->whereIn('method', ['pos', 'transfer'])->sum('amount'),
                        'payments' => $shift->payments->sortByDesc('paid_at'),
                    ];
                }),
                'total_shifts' => $userShifts->count(),
                'total_duration' => $userShifts->whereNotNull('ended_at')->sum(fn($s) => $s->started_at->diffInMinutes($s->ended_at)),
                'total_payments' => $userShifts->sum(fn($s) => $s->payments->sum('amount')),
                'total_transactions' => $userShifts->sum(fn($s) => $s->payments->count()),
            ];
        })->sortBy('user.name');

        return [
            'reportDate' => $targetDate->format('l, d M Y'),
            'staffShifts' => $staffShifts,
            'totalStaff' => $staffShifts->count(),
            'totalShifts' => $shifts->count(),
            'activeShifts' => $shifts->whereNull('ended_at')->count(),
            'completedShifts' => $shifts->whereNotNull('ended_at')->count(),
            'totalPayments' => $shifts->sum(fn($s) => $s->payments->sum('amount')),
        ];
    }
}