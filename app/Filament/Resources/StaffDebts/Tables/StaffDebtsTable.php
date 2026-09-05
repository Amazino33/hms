<?php

namespace App\Filament\Resources\StaffDebts\Tables;

use App\Models\StaffDebt;
use App\Services\StaffDebtReportService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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

                // Labelled from the shared map, not a local match() with a
                // 'Manual' default — that default silently mislabelled every
                // reason added after this column was written (count session,
                // reception and cashier-session shortfalls) as manual entries.
                TextColumn::make('reason')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => StaffDebtReportService::reasonLabel($state)),

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

                TextColumn::make('origin')
                    ->label('Source')
                    ->badge()
                    ->state(fn (StaffDebt $record): string => StaffDebtReportService::isHandoverReason($record->reason)
                        ? 'During handover'
                        : 'Recorded by a person')
                    ->color(fn (string $state): string => $state === 'During handover' ? 'info' : 'gray'),

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
                    ->options(StaffDebtReportService::REASON_LABELS)
                    ->multiple(),
                SelectFilter::make('origin')
                    ->label('Source')
                    ->options([
                        'handover' => 'Raised during a handover',
                        'recorded' => 'Recorded by a person',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'handover' => $query->whereIn('reason', StaffDebtReportService::HANDOVER_REASONS),
                        'recorded' => $query->whereNotIn('reason', StaffDebtReportService::HANDOVER_REASONS),
                        default => $query,
                    }),
                SelectFilter::make('user')
                    ->label('Staff')
                    ->relationship('user', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload(),
                Filter::make('raised_between')
                    ->label('Date raised')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                Action::make('viewRepayments')
                    ->label('Repayment History')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->modalHeading(fn (StaffDebt $record) => 'Repayment history — '.($record->user->name ?? 'Unknown'))
                    ->modalContent(fn (StaffDebt $record) => view('filament.resources.staff-debts.repayment-history', [
                        'debt' => $record->loadMissing('repayments.recordedBy'),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                Action::make('recordRepayment')
                    ->label('Record Repayment')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (StaffDebt $record) => $record->status !== 'settled')
                    ->form(fn (StaffDebt $record) => [
                        TextInput::make('amount')
                            ->numeric()
                            ->prefix('₦')
                            ->required()
                            ->minValue(0.01)
                            ->maxValue($record->remainingBalance())
                            ->helperText('Outstanding balance: ₦'.number_format($record->remainingBalance(), 2)),
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
