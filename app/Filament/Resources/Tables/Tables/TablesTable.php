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
                        'available' => 'success',    // Green
                        'occupied' => 'danger',      // Red
                        'reserved' => 'warning',     // Amber
                        'cleaning' => 'info',        // Blue
                        'maintenance' => 'gray',     // Gray
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
                Action::make('quick_available')
                    ->label('Mark Available')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn ($record) => $record->status !== 'available')
                    ->action(fn ($record) => $record->update(['status' => 'available'])),
                
                Action::make('quick_cleaning')
                    ->label('Start Cleaning')
                    ->icon('heroicon-o-sparkles')
                    ->color('info')
                    ->visible(fn ($record) => $record->status !== 'cleaning')
                    ->action(fn ($record) => $record->update(['status' => 'cleaning'])),
                
                Action::make('quick_maintenance')
                    ->label('Maintenance')
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->color('gray')
                    ->visible(fn ($record) => $record->status !== 'maintenance')
                    ->action(fn ($record) => $record->update(['status' => 'maintenance'])),
                
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
                    \Filament\Actions\BulkAction::make('mark_available')
                        ->label('Mark as Available')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->action(fn ($records) => $records->each->update(['status' => 'available'])),
                    
                    \Filament\Actions\BulkAction::make('mark_cleaning')
                        ->label('Mark as Cleaning')
                        ->icon('heroicon-o-sparkles')
                        ->color('info')
                        ->action(fn ($records) => $records->each->update(['status' => 'cleaning'])),
                    
                    \Filament\Actions\BulkAction::make('mark_maintenance')
                        ->label('Mark as Maintenance')
                        ->icon('heroicon-o-wrench-screwdriver')
                        ->color('gray')
                        ->action(fn ($records) => $records->each->update(['status' => 'maintenance'])),
                    
                    DeleteBulkAction::make()
                        ->label('Delete Selected')
                        ->action(fn ($records) => $records->each->delete())
                        ->color('danger'),
                ]),
            ]);
    }
}
