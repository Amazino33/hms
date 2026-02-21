<?php

namespace App\Filament\Resources\ShiftManagement\Tables;

use App\Models\Shift;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
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
                    ->visible(fn (Shift $record): bool => $record->status === 'pending_supervisor')
                    ->form([
                        TextInput::make('supervisor_confirmed_cash')
                            ->label('Supervisor Confirmed Cash')
                            ->numeric()
                            ->required()
                            ->minValue(0),
                        TextInput::make('supervisor_confirmed_pos')
                            ->label('Supervisor Confirmed POS')
                            ->numeric()
                            ->required()
                            ->minValue(0),
                    ])
                    ->action(function (Shift $record, array $data): void {
                        $record->update([
                            'supervisor_confirmed_cash' => $data['supervisor_confirmed_cash'],
                            'supervisor_confirmed_pos' => $data['supervisor_confirmed_pos'],
                            'status' => 'closed',
                        ]);
                    }),
            ]);
    }
}
