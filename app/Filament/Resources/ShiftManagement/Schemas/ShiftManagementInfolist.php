<?php

namespace App\Filament\Resources\ShiftManagement\Schemas;

use App\Models\Order;
use App\Models\Shift;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid as ComponentsGrid;
use Filament\Schemas\Components\Section as ComponentsSection;
use Filament\Schemas\Schema;

class ShiftManagementInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->schema([
                ComponentsSection::make('System Expected Totals')
                    ->schema([
                        ComponentsGrid::make(3)->schema([
                            TextEntry::make('system_expected_cash')
                                ->label('Expected Cash')
                                ->money('NGN')
                                ->state(fn (Shift $record) => self::getSystemExpectedTotals($record)['cash']),
                            TextEntry::make('system_expected_pos')
                                ->label('Expected POS')
                                ->money('NGN')
                                ->state(fn (Shift $record) => self::getSystemExpectedTotals($record)['pos']),
                            TextEntry::make('system_expected_total')
                                ->label('Expected Total')
                                ->money('NGN')
                                ->state(fn (Shift $record) => self::getSystemExpectedTotals($record)['total']),
                        ]),
                    ]),
                ComponentsSection::make('Waiter Declared Totals')
                    ->schema([
                        ComponentsGrid::make(3)->schema([
                            TextEntry::make('declared_cash')
                                ->label('Declared Cash')
                                ->money('NGN')
                                ->state(fn (Shift $record) => $record->declared_cash ?? 0),
                            TextEntry::make('declared_pos')
                                ->label('Declared POS')
                                ->money('NGN')
                                ->state(fn (Shift $record) => $record->declared_pos ?? 0),
                            TextEntry::make('declared_total')
                                ->label('Declared Total')
                                ->money('NGN')
                                ->state(fn (Shift $record) => ($record->declared_cash ?? 0) + ($record->declared_pos ?? 0)),
                        ]),
                    ]),
                ComponentsSection::make('Supervisor Confirmed Totals')
                    ->schema([
                        ComponentsGrid::make(3)->schema([
                            TextEntry::make('supervisor_confirmed_cash')
                                ->label('Confirmed Cash')
                                ->money('NGN')
                                ->state(fn (Shift $record) => $record->supervisor_confirmed_cash ?? 0),
                            TextEntry::make('supervisor_confirmed_pos')
                                ->label('Confirmed POS')
                                ->money('NGN')
                                ->state(fn (Shift $record) => $record->supervisor_confirmed_pos ?? 0),
                            TextEntry::make('supervisor_confirmed_total')
                                ->label('Confirmed Total')
                                ->money('NGN')
                                ->state(fn (Shift $record) => ($record->supervisor_confirmed_cash ?? 0) + ($record->supervisor_confirmed_pos ?? 0)),
                        ]),
                    ]),
                ComponentsSection::make('Discrepancy (Confirmed - Expected)')
                    ->schema([
                        ComponentsGrid::make(3)->schema([
                            TextEntry::make('discrepancy_cash')
                                ->label('Cash Discrepancy')
                                ->money('NGN')
                                ->badge()
                                ->color(fn ($state) => $state < 0 ? 'danger' : ($state > 0 ? 'success' : 'gray'))
                                ->state(fn (Shift $record) => ($record->supervisor_confirmed_cash ?? 0) - self::getSystemExpectedTotals($record)['cash']),
                            TextEntry::make('discrepancy_pos')
                                ->label('POS Discrepancy')
                                ->money('NGN')
                                ->badge()
                                ->color(fn ($state) => $state < 0 ? 'danger' : ($state > 0 ? 'success' : 'gray'))
                                ->state(fn (Shift $record) => ($record->supervisor_confirmed_pos ?? 0) - self::getSystemExpectedTotals($record)['pos']),
                            TextEntry::make('discrepancy_total')
                                ->label('Total Discrepancy')
                                ->money('NGN')
                                ->badge()
                                ->color(fn ($state) => $state < 0 ? 'danger' : ($state > 0 ? 'success' : 'gray'))
                                ->state(fn (Shift $record) => (($record->supervisor_confirmed_cash ?? 0) + ($record->supervisor_confirmed_pos ?? 0)) - self::getSystemExpectedTotals($record)['total']),
                        ]),
                    ]),
            ]);
    }

    private static function getSystemExpectedTotals(Shift $record): array
    {
        static $cache = [];

        if (array_key_exists($record->id, $cache)) {
            return $cache[$record->id];
        }

        $endAt = $record->ended_at ?? now();

        $orders = Order::query()
            ->where('user_id', $record->user_id)
            ->whereBetween('created_at', [$record->started_at, $endAt])
            ->whereIn('status', ['paid', 'partial']);

        $cash = (float) $orders->sum('paid_cash');
        $pos = (float) $orders->sum('paid_pos');

        return $cache[$record->id] = [
            'cash' => $cash,
            'pos' => $pos,
            'total' => $cash + $pos,
        ];
    }
}
