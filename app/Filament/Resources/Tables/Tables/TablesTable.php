<?php

namespace App\Filament\Resources\Tables\Tables;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TablesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // 1. Name
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                // 2. Capacity
                TextColumn::make('capacity')
                    ->sortable()
                    ->label('Seats')
                    ->alignment('center'),

                // 3. Status (With Colors)
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'available' => 'success', // Green
                        'occupied' => 'danger',   // Red
                        'reserved' => 'warning',  // Amber
                    }),

                // 4. Location
                TextColumn::make('location')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
            ])
            ->filters([
                // Filter by Location or Status
                SelectFilter::make('status'),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil')
                    ->url(fn ($record) => url("/admin/tables/{$record->id}/edit")),
                Action::make('delete')
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->action(fn ($record) => $record->delete()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label('Delete Selected')
                        ->action(fn ($records) => $records->each->delete())
                        ->color('danger'),
                ]),
            ]);
    }
}
