<?php

namespace App\Filament\Resources\Rooms;

use App\Filament\Resources\Rooms\Pages\CreateRoom;
use App\Filament\Resources\Rooms\Pages\EditRoom;
use App\Filament\Resources\Rooms\Pages\ListRooms;
use App\Filament\Resources\Rooms\Schemas\RoomForm;
use App\Filament\Resources\Rooms\Tables\RoomsTable;
use App\Models\Room;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;

class RoomResource extends Resource
{
    protected static ?string $model = Room::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-home';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
        ->schema([
            TextInput::make('number')
                ->required()
                ->unique(ignoreRecord: true) // Ensure Room 101 isn't created twice
                ->maxLength(255),

            Select::make('type')
                ->options([
                    'Single' => 'Single Room',
                    'Double' => 'Double Room',
                    'Suite' => 'Executive Suite',
                    'Hall' => 'Event Hall',
                ])
                ->required(),

            TextInput::make('price_per_night')
                ->numeric()
                ->prefix('₦')
                ->required(),

            Select::make('status')
                ->options([
                    'available' => 'Available',
                    'occupied' => 'Occupied',
                    'maintenance' => 'Maintenance',
                ])
                ->default('available')
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
        ->columns([
            TextColumn::make('number')->sortable()->searchable()->weight('bold'),
            TextColumn::make('type')->sortable(),
            TextColumn::make('price_per_night')->money('NGN')->sortable(),
            
            TextColumn::make('status')
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    'available' => 'success',
                    'occupied' => 'danger',
                    'maintenance' => 'warning',
                }),
        ])
        ->filters([
            //
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
            'index' => ListRooms::route('/'),
            'create' => CreateRoom::route('/create'),
            'edit' => EditRoom::route('/{record}/edit'),
        ];
    }
}
