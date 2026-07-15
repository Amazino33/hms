<?php

namespace App\Filament\Resources\ShiftManagement\Tables;

use App\Models\Shift;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The old single-step "Review & Confirm" action lived here — a supervisor
 * confirming and closing a shift in one click. Removed: the cashier is
 * now the primary confirmer for every settlement (CashierSettlementService),
 * channel by channel, and no settlement closes without her. A supervisor's
 * remaining settlement powers (fallback confirmation, flag rulings) use
 * the identical confirmation screen the cashier uses — linked below —
 * rather than a separate one-shot form.
 */
class ShiftManagementTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('Staff')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                TextColumn::make('started_at')
                    ->label('Shift Date')
                    ->dateTime('M d, Y g:i A')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'info',
                        'awaiting_cashier' => 'warning',
                        'confirmed' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'awaiting_cashier' => 'Awaiting Cashier',
                        default => ucfirst(str_replace('_', ' ', $state)),
                    }),
                TextColumn::make('declared_cash')
                    ->label('Declared Cash')
                    ->money('NGN')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('cashier_counted_cash')
                    ->label('Cashier-Counted Cash')
                    ->money('NGN')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('cash_variance')
                    ->label('Variance')
                    ->money('NGN')
                    ->placeholder('—')
                    ->color(fn (?string $state): string => match (true) {
                        $state === null => 'gray',
                        (float) $state < 0 => 'danger',
                        (float) $state > 0 => 'warning',
                        default => 'success',
                    }),
            ])
            ->defaultSort('started_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'awaiting_cashier' => 'Awaiting Cashier',
                        'confirmed' => 'Confirmed',
                    ]),
            ])
            ->recordActions([
                Action::make('viewSettlement')
                    ->label('View / Confirm')
                    ->color('primary')
                    ->icon('heroicon-o-check-badge')
                    ->visible(fn (Shift $record): bool => $record->status === 'awaiting_cashier')
                    ->url(fn (Shift $record): string => '/admin/settlement?shift=' . $record->id),
            ]);
    }
}
