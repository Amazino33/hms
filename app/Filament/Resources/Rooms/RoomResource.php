<?php

namespace App\Filament\Resources\Rooms;

use App\Filament\Resources\Rooms\Pages\CreateRoom;
use App\Filament\Resources\Rooms\Pages\EditRoom;
use App\Filament\Resources\Rooms\Pages\ListRooms;
use App\Models\Room;
use BackedEnum;
use UnitEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
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

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

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
                ->label('Maintenance status')
                ->options([
                    'available' => 'Available',
                    'maintenance' => 'Maintenance',
                ])
                ->default('available')
                ->required()
                ->helperText('Occupancy is shown separately below, derived from bookings — this is only a manual out-of-service flag.'),

            Select::make('housekeeping')
                ->options([
                    'clean' => 'Clean',
                    'dirty' => 'Dirty',
                ])
                ->default('clean')
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
                ->label('Maintenance')
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    'available' => 'success',
                    'maintenance' => 'warning',
                    default => 'gray',
                }),
            TextColumn::make('housekeeping')
                ->badge()
                ->color(fn (string $state): string => $state === 'clean' ? 'success' : 'gray'),
            TextColumn::make('occupancy')
                ->label('Occupancy (today)')
                ->state(fn (Room $record): string => str($record->occupancyState())->replace('_', ' ')->title())
                ->badge()
                ->color(fn (Room $record): string => match ($record->occupancyState()) {
                    'vacant' => 'success',
                    'occupied' => 'primary',
                    'due_out_today' => 'warning',
                    'arriving_today' => 'info',
                    default => 'gray',
                }),
        ])
        ->filters([
            //
        ])
        ->recordActions([
            Action::make('edit')
                ->icon('heroicon-o-pencil')
                ->label('Edit')
                ->url(fn (Room $record) => EditRoom::getUrl(['record' => $record])),
            Action::make('delete')
                ->requiresConfirmation()
                ->label('Delete')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->action(fn (Room $record) => $record->delete()),
        ])
        ->toolbarActions([
            BulkAction::make('delete')
                ->icon('heroicon-o-trash')
                ->label('Delete Selected')
                ->requiresConfirmation()
                ->color('danger')
                ->action(fn (array $records) => Room::whereIn('id', $records)->delete()),
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
