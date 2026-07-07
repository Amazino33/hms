<?php

namespace App\Filament\Resources\Warehouses;

use App\Filament\Resources\Warehouses\Pages\CreateWarehouse;
use App\Filament\Resources\Warehouses\Pages\EditWarehouse;
use App\Filament\Resources\Warehouses\Pages\ListWarehouses;
use App\Models\WareHouse;
use BackedEnum;
use UnitEnum;
use Filament\Resources\Resource;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;

class WarehouseResource extends Resource
{
    protected static ?string $model = WareHouse::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static string|UnitEnum|null $navigationGroup = 'Inventory Management';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
        ->schema([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->placeholder('e.g., Main Store, Bar Fridge'),
            
            Select::make('type')
                ->options([
                    'storage' => 'Storage',
                    'consumer' => 'Consumer',
                ])
                ->required()
                ->default('storage')
                ->helperText('Storage: Can have direct inventory input. Consumer: Receives stock through transfers only.'),

            TextInput::make('location')
                ->maxLength(255)
                ->placeholder('e.g., Basement, Rooftop Bar'),

            TextInput::make('sub_location_labels.0')
                ->label('Sub-location 1 label')
                ->maxLength(50)
                ->placeholder('Defaults to Fridge (Bar) / Shelf A (others)')
                ->helperText('These 3 labels are what counters see as the fixed sub-locations during a count session at this warehouse. Leave all 3 blank to use the defaults.'),

            TextInput::make('sub_location_labels.1')
                ->label('Sub-location 2 label')
                ->maxLength(50)
                ->placeholder('Defaults to Floor (Bar) / Shelf B (others)'),

            TextInput::make('sub_location_labels.2')
                ->label('Sub-location 3 label')
                ->maxLength(50)
                ->placeholder('Defaults to Shelf (Bar) / Shelf C (others)'),

            Toggle::make('is_active')
                ->label('Active Warehouse')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'storage' => 'success',
                        'consumer' => 'info',
                        default => 'gray',
                    })
                    ->searchable(),

                TextColumn::make('location')
                    ->icon('heroicon-m-map-pin')
                    ->searchable(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->recordActions([
                Action::make('edit')
                    ->icon('heroicon-o-pencil')
                    ->label('Edit')
                    ->url(fn (WareHouse $record) => EditWarehouse::getUrl(['record' => $record])),

                Action::make('delete')
                    ->requiresConfirmation()
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->action(fn (WareHouse $record) => $record->delete()),
            ])
            ->toolbarActions([
                BulkAction::make('delete')
                    ->icon('heroicon-o-trash')
                    ->label('Delete Selected')
                    ->requiresConfirmation()
                    ->color('danger')
                    ->action(fn (array $records) => WareHouse::whereIn('id', $records)->delete()),
            ]);
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
            'index' => ListWarehouses::route('/'),
            'create' => CreateWarehouse::route('/create'),
            'edit' => EditWarehouse::route('/{record}/edit'),
        ];
    }
}
