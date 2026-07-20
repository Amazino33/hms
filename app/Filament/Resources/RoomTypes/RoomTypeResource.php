<?php

namespace App\Filament\Resources\RoomTypes;

use App\Filament\Resources\RoomTypes\Pages\CreateRoomType;
use App\Filament\Resources\RoomTypes\Pages\EditRoomType;
use App\Filament\Resources\RoomTypes\Pages\ListRoomTypes;
use App\Models\RoomType;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Super-admin only (see the Policy + ShieldSeeder entry). A fixed, seeded
 * list — add/rename/deactivate, never delete, so a Room always keeps a
 * valid type reference and existing bookings/history are never orphaned.
 */
class RoomTypeResource extends Resource
{
    protected static ?string $model = RoomType::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-home-modern';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Room Types';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            TextInput::make('name')->required()->maxLength(255)->unique(ignoreRecord: true),
            TextInput::make('price_per_night')
                ->label('Price per Night')
                ->numeric()
                ->prefix('₦')
                ->required(),
            Toggle::make('is_active')->label('Active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('price_per_night')->label('Price per Night')->money('NGN')->sortable(),
                IconColumn::make('is_active')->boolean()->label('Active'),
                TextColumn::make('rooms_count')->label('Rooms')->counts('rooms'),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoomTypes::route('/'),
            'create' => CreateRoomType::route('/create'),
            'edit' => EditRoomType::route('/{record}/edit'),
        ];
    }
}
