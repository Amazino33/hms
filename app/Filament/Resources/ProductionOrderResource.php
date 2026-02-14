<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductionOrderResource\Pages;
use App\Models\ProductionOrder;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Tables\Actions\Action;
use BackedEnum;
use Filament\Actions\Action as ActionsAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Schema;
use UnitEnum;

class ProductionOrderResource extends Resource
{
    protected static ?string $model = ProductionOrder::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog';

    protected static string|UnitEnum|null $navigationGroup = 'Kitchen Management';

    protected static ?string $navigationLabel = 'Production Orders';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\TextInput::make('menu_item_name')
                    ->label('Menu Item')
                    ->disabled(),

                Forms\Components\TextInput::make('quantity')
                    ->numeric()
                    ->disabled(),

                Forms\Components\Select::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'in_progress' => 'In Progress',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled',
                    ])
                    ->required(),

                Forms\Components\Select::make('priority')
                    ->options([
                        'low' => 'Low',
                        'normal' => 'Normal',
                        'high' => 'High',
                        'urgent' => 'Urgent',
                    ]),

                Forms\Components\Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('menu_item_name')
                    ->label('Menu Item')
                    ->searchable(),

                Tables\Columns\TextColumn::make('quantity')
                    ->numeric(),

                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'secondary' => 'pending',
                        'warning' => 'in_progress',
                        'success' => 'completed',
                        'danger' => 'cancelled',
                    ]),

                Tables\Columns\TextColumn::make('priority')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'low' => 'gray',
                        'normal' => 'gray',
                        'high' => 'warning',
                        'urgent' => 'danger',
                    }),

                Tables\Columns\TextColumn::make('orderItem.order.order_number')
                    ->label('Order')
                    ->sortable(),

                Tables\Columns\TextColumn::make('assignedUser.name')
                    ->label('Assigned To'),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('completed_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'in_progress' => 'In Progress',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled',
                    ]),

                Tables\Filters\SelectFilter::make('priority')
                    ->options([
                        'low' => 'Low',
                        'normal' => 'Normal',
                        'high' => 'High',
                        'urgent' => 'Urgent',
                    ]),
            ])
            ->actions([
                ActionsAction::make('start_production')
                    ->label('Start Production')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->visible(fn (ProductionOrder $record) => $record->status === 'pending')
                    ->action(function (ProductionOrder $record) {
                        ProductionOrderService::startProduction($record, auth()->id());
                        \Filament\Notifications\Notification::make()
                            ->title('Production Started')
                            ->success()
                            ->send();
                    }),

                ActionsAction::make('complete_production')
                    ->label('Complete Production')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (ProductionOrder $record) => $record->status === 'in_progress')
                    ->action(function (ProductionOrder $record) {
                        ProductionOrderService::completeProduction($record);
                        \Filament\Notifications\Notification::make()
                            ->title('Production Completed')
                            ->success()
                            ->send();
                    }),

                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductionOrders::route('/'),
            'create' => Pages\CreateProductionOrder::route('/create'),
            'edit' => Pages\EditProductionOrder::route('/{record}/edit'),
        ];
    }
}