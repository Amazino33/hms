<?php

namespace App\Filament\Resources\StaffDebts\Tables;

use App\Models\StaffDebt;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class StaffDebtsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('user.name')
                    ->label('Staff Member')
                    ->weight('bold')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('reason')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'shift_shortfall' => 'Shift Shortfall',
                        'unpaid_order_conversion' => 'Unpaid Order',
                        default => 'Manual',
                    }),

                TextColumn::make('amount')
                    ->money('NGN')
                    ->sortable(),

                TextColumn::make('remaining')
                    ->label('Remaining')
                    ->state(fn (StaffDebt $record) => $record->remainingBalance())
                    ->money('NGN')
                    ->color(fn (StaffDebt $record) => $record->remainingBalance() > 0 ? 'danger' : 'success'),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'open' => 'danger',
                        'partially_settled' => 'warning',
                        'settled' => 'success',
                        default => 'gray',
                    }),

                TextColumn::make('shift.started_at')
                    ->label('Shift')
                    ->dateTime('M j, Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('creator.name')
                    ->label('Opened By')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'open' => 'Open',
                        'partially_settled' => 'Partially Settled',
                        'settled' => 'Settled',
                    ]),
                SelectFilter::make('reason')
                    ->options([
                        'shift_shortfall' => 'Shift Shortfall',
                        'unpaid_order_conversion' => 'Unpaid Order',
                        'manual' => 'Manual',
                    ]),
                SelectFilter::make('user')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                Action::make('recordRepayment')
                    ->label('Record Repayment')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (StaffDebt $record) => $record->status !== 'settled')
                    ->form([
                        TextInput::make('amount')
                            ->numeric()
                            ->prefix('₦')
                            ->required()
                            ->minValue(0.01),
                        Select::make('method')
                            ->options([
                                'cash' => 'Cash',
                                'commission_offset' => 'Commission Offset',
                                'salary_deduction' => 'Salary Deduction',
                                'other' => 'Other',
                            ])
                            ->default('cash')
                            ->required(),
                        Textarea::make('notes')
                            ->columnSpanFull(),
                    ])
                    ->action(function (StaffDebt $record, array $data): void {
                        $record->repayments()->create([
                            'amount' => $data['amount'],
                            'method' => $data['method'],
                            'notes' => $data['notes'] ?? null,
                            'recorded_by' => auth()->id(),
                        ]);

                        $record->refreshStatus();

                        Notification::make()
                            ->title('Repayment recorded')
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
