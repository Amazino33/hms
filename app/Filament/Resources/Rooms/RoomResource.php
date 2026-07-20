<?php

namespace App\Filament\Resources\Rooms;

use App\Filament\Resources\Rooms\Pages\CreateRoom;
use App\Filament\Resources\Rooms\Pages\EditRoom;
use App\Filament\Resources\Rooms\Pages\ListRooms;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\UserFeedback;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

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

                Select::make('room_type_id')
                    ->label('Room Type')
                    ->options(fn () => RoomType::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->required()
                    ->live()
                    // A starting point pulled from the type's configured price,
                    // not a locked value — still just a normal editable field
                    // afterward, same as if the room's own price genuinely
                    // differs from its type (a corner room worth more, say).
                    ->afterStateUpdated(function (callable $set, $state) {
                        $set('price_per_night', RoomType::find($state)?->price_per_night);
                    }),

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
                TextColumn::make('roomType.name')->label('Type')->sortable(),
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
                    ->action(function (Room $record) {
                        try {
                            $record->delete();
                            UserFeedback::succeeded('Room deleted');
                        } catch (\Throwable $e) {
                            report($e);
                            UserFeedback::blocked('Cannot delete room', 'This room still has bookings on record. Remove or reassign them first.');
                        }
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('delete')
                    ->icon('heroicon-o-trash')
                    ->label('Delete Selected')
                    ->requiresConfirmation()
                    ->color('danger')
                    ->action(function (array $records) {
                        try {
                            $count = Room::whereIn('id', $records)->delete();
                            UserFeedback::succeeded("{$count} room(s) deleted");
                        } catch (\Throwable $e) {
                            report($e);
                            UserFeedback::blocked('Could not delete', 'One or more selected rooms still have bookings on record. Remove or reassign them first.');
                        }
                    }),
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
