<?php

namespace App\Filament\Resources\ShiftManagement\Tables;

use App\Models\Shift;
use App\Services\ShiftAccountingService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ShiftManagementTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('Waiter')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('started_at')
                    ->label('Shift Date')
                    ->dateTime('M d, Y g:i A')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'info',
                        'pending_supervisor' => 'warning',
                        'closed' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending_supervisor' => 'Pending Supervisor',
                        default => ucfirst(str_replace('_', ' ', $state)),
                    }),
                TextColumn::make('declared_cash')
                    ->label('Declared Cash')
                    ->money('NGN')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('declared_pos')
                    ->label('Declared POS')
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
                        'pending_supervisor' => 'Pending Supervisor',
                        'closed' => 'Closed',
                    ]),
            ])
            ->recordActions([
                Action::make('reviewShift')
                    ->label('Review & Confirm')
                    ->color('primary')
                    ->icon('heroicon-o-check-badge')
                    ->visible(fn (Shift $record): bool => $record->status === 'pending_supervisor'
                        && auth()->user()->hasRole(['manager', 'admin', 'super_admin']))
                    ->authorize(fn (): bool => auth()->user()->hasRole(['manager', 'admin', 'super_admin']))
                    ->form(function (Shift $record): array {
                        $service = new ShiftAccountingService();
                        $expectedCash = $service->expectedCashRemittance($record);
                        $expectedPos = $service->expectedPosTotal($record);

                        return [
                            Placeholder::make('expected_summary')
                                ->label('System-computed expectations')
                                ->content("Expected cash: ₦" . number_format($expectedCash, 2) . " · Expected POS: ₦" . number_format($expectedPos, 2)),
                            TextInput::make('supervisor_confirmed_cash')
                                ->label('Supervisor Confirmed Cash')
                                ->numeric()
                                ->required()
                                ->minValue(0)
                                ->default($record->declared_cash),
                            TextInput::make('supervisor_confirmed_pos')
                                ->label('Supervisor Confirmed POS')
                                ->numeric()
                                ->required()
                                ->minValue(0)
                                ->default($record->declared_pos),
                            Textarea::make('settlement_notes')
                                ->label('Notes')
                                ->columnSpanFull(),
                        ];
                    })
                    ->action(function (Shift $record, array $data): void {
                        try {
                            $debt = (new ShiftAccountingService())->applyShiftSettlement(
                                $record,
                                auth()->user(),
                                (float) $data['supervisor_confirmed_cash'],
                                (float) $data['supervisor_confirmed_pos'],
                                $data['settlement_notes'] ?? null,
                            );
                        } catch (\Exception $e) {
                            Notification::make()->title('Could not close shift')->body($e->getMessage())->danger()->send();
                            return;
                        }

                        if ($debt) {
                            Notification::make()
                                ->title('Shift closed with a shortfall')
                                ->body('₦' . number_format($debt->amount, 2) . ' recorded as a staff debt for ' . $record->user->name)
                                ->warning()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Shift closed')
                                ->success()
                                ->send();
                        }
                    }),
            ]);
    }
}
