<?php

namespace App\Filament\Resources\Tables\Tables;

use App\Services\UserFeedback;
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
                        default => 'gray',
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
                // Note: none of the three quick-status actions below guard
                // against flipping a table's status while it has an active
                // order — that's a real, pre-existing gap this pass does not
                // fix (adding that guard would be new business logic, not a
                // communication fix — out of scope here). Only the missing
                // success/failure feedback is added.
                Action::make('quick_available')
                    ->label('Mark Available')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn ($record) => $record->status !== 'available')
                    ->action(function ($record) {
                        try {
                            $record->update(['status' => 'available']);
                            UserFeedback::succeeded("{$record->name} marked available");
                        } catch (\Throwable $e) {
                            report($e);
                            UserFeedback::failed('Could not update table status');
                        }
                    }),

                Action::make('quick_cleaning')
                    ->label('Start Cleaning')
                    ->icon('heroicon-o-sparkles')
                    ->color('info')
                    ->visible(fn ($record) => $record->status !== 'cleaning')
                    ->action(function ($record) {
                        try {
                            $record->update(['status' => 'cleaning']);
                            UserFeedback::succeeded("{$record->name} marked cleaning");
                        } catch (\Throwable $e) {
                            report($e);
                            UserFeedback::failed('Could not update table status');
                        }
                    }),

                Action::make('quick_maintenance')
                    ->label('Maintenance')
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->color('gray')
                    ->visible(fn ($record) => $record->status !== 'maintenance')
                    ->action(function ($record) {
                        try {
                            $record->update(['status' => 'maintenance']);
                            UserFeedback::succeeded("{$record->name} marked maintenance");
                        } catch (\Throwable $e) {
                            report($e);
                            UserFeedback::failed('Could not update table status');
                        }
                    }),

                Action::make('edit')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil')
                    ->url(fn ($record) => url("/admin/tables/{$record->id}/edit")),

                Action::make('delete')
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->action(function ($record) {
                        try {
                            $record->delete();
                            UserFeedback::succeeded('Table deleted');
                        } catch (\Throwable $e) {
                            report($e);
                            UserFeedback::blocked('Cannot delete table', 'This table still has orders on record.');
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    \Filament\Actions\BulkAction::make('mark_available')
                        ->label('Mark as Available')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->action(function ($records) {
                            try {
                                $count = $records->count();
                                $records->each->update(['status' => 'available']);
                                UserFeedback::succeeded("{$count} table(s) marked available");
                            } catch (\Throwable $e) {
                                report($e);
                                UserFeedback::failed('Could not update table status');
                            }
                        }),

                    \Filament\Actions\BulkAction::make('mark_cleaning')
                        ->label('Mark as Cleaning')
                        ->icon('heroicon-o-sparkles')
                        ->color('info')
                        ->action(function ($records) {
                            try {
                                $count = $records->count();
                                $records->each->update(['status' => 'cleaning']);
                                UserFeedback::succeeded("{$count} table(s) marked cleaning");
                            } catch (\Throwable $e) {
                                report($e);
                                UserFeedback::failed('Could not update table status');
                            }
                        }),

                    \Filament\Actions\BulkAction::make('mark_maintenance')
                        ->label('Mark as Maintenance')
                        ->icon('heroicon-o-wrench-screwdriver')
                        ->color('gray')
                        ->action(function ($records) {
                            try {
                                $count = $records->count();
                                $records->each->update(['status' => 'maintenance']);
                                UserFeedback::succeeded("{$count} table(s) marked maintenance");
                            } catch (\Throwable $e) {
                                report($e);
                                UserFeedback::failed('Could not update table status');
                            }
                        }),

                    DeleteBulkAction::make()
                        ->label('Delete Selected')
                        ->action(function ($records) {
                            try {
                                $count = $records->count();
                                $records->each->delete();
                                UserFeedback::succeeded("{$count} table(s) deleted");
                            } catch (\Throwable $e) {
                                report($e);
                                UserFeedback::blocked('Could not delete', 'One or more selected tables still have orders on record.');
                            }
                        })
                        ->color('danger'),
                ]),
            ]);
    }
}
