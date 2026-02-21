<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use App\Models\OrderPayment;
use App\Models\Order;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Carbon\Carbon;
use BackedEnum;
use Filament\Schemas\Schema;

class DailyReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-presentation-chart-line';
    protected static ?string $navigationLabel = 'End of Day Record';
    protected static ?int $navigationSort = 2; // Show near the top
    protected string $view = 'filament.pages.daily-report';

    public ?array $data = [];

    public function mount(): void
    {
        // Default to Today
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
                    ->live() // Auto-refresh when date changes
                    ->afterStateUpdated(fn () => $this->calculateStats()),
            ])->statePath('data');
    }

    // This function calculates all the numbers for the View
    protected function getViewData(): array
    {
        $date = $this->data['date'] ?? now()->format('Y-m-d');
        $targetDate = Carbon::parse($date);

        // 1. CASH FLOW - Now use paid_cash and paid_pos from orders
        $orders = Order::whereDate('created_at', $targetDate)
            ->whereIn('status', ['paid', 'partial'])
            ->get();

        $cashHand = $orders->sum('paid_cash');
        $posBank = $orders->sum('paid_pos');
        $totalCollected = $cashHand + $posBank;

        // 2. DEBT (Total outstanding debt)
        $totalDebt = Order::where('status', 'partial')
            ->get()
            ->sum(fn($order) => $order->total_amount - $order->amount_paid);

        // 3. STAFF PERFORMANCE (Who collected what)
        $payments = OrderPayment::whereDate('paid_at', $targetDate)->with('user')->get();
        $staffStats = $payments->groupBy('user_id')
            ->map(fn ($items) => [
                'name' => $items->first()->user->name ?? 'Unknown',
                'total' => $items->sum('amount'),
                'count' => $items->count(),
            ])->sortByDesc('total');

        return [
            'reportDate' => $targetDate->format('l, d M Y'),
            'totalCollected' => $totalCollected,
            'cashHand' => $cashHand,
            'posBank' => $posBank,
            'totalDebt' => $totalDebt,
            'staffStats' => $staffStats,
        ];
    }
}