<?php

namespace App\Filament\Resources\Bookings;

use App\Filament\Resources\Bookings\Pages\CreateBooking;
use App\Filament\Resources\Bookings\Pages\EditBooking;
use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Models\Booking;
use BackedEnum;
use UnitEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Carbon\Carbon;
use App\Models\Room;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;

class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with(['guest', 'room']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
        ->schema([
            Select::make('guest_id')
                ->relationship('guest', 'name')
                ->searchable()
                ->preload()
                ->createOptionForm([
                    TextInput::make('name')->required(),
                    TextInput::make('phone'),
                ])
                ->required(),

            Select::make('room_id')
                ->options(fn () => Room::available()->get()->reject(fn (Room $room) => $room->isOccupiedToday())->pluck('number', 'id'))
                ->required()
                ->live() // Watch for changes
                ->afterStateUpdated(function (Get $get, Set $set) {
                    self::calculateTotal($get, $set);
                }),

            DatePicker::make('check_in')
                ->default(now())
                ->required()
                ->live()
                ->afterStateUpdated(fn (Get $get, Set $set) => self::calculateTotal($get, $set)),

            DatePicker::make('check_out')
                ->default(now()->addDay())
                ->required()
                ->live()
                ->afterStateUpdated(fn (Get $get, Set $set) => self::calculateTotal($get, $set)),

            TextInput::make('total_price')
                ->numeric()
                ->prefix('₦')
                ->readOnly(), // Auto-calculated, so user can't edit manually

            Toggle::make('is_paid')->label('Payment Received'),
        ]);
    }

    // Helper function to calculate price
    public static function calculateTotal(Get $get, Set $set)
    {
        $roomId = $get('room_id');
        $checkIn = $get('check_in');
        $checkOut = $get('check_out');

        $room = $roomId ? Room::find($roomId) : null;

        if ($room && $checkIn && $checkOut) {
            $days = Carbon::parse($checkIn)->diffInDays(Carbon::parse($checkOut));
            $days = $days < 1 ? 1 : $days; // Minimum 1 night

            $set('total_price', $room->price_per_night * $days);
        }
    }

    public static function table(Table $table): Table
    {
        return $table
        ->columns([
            // 1. Show Guest Name (Relationship)
            TextColumn::make('guest.name')
                ->label('Guest')
                ->searchable()
                ->sortable()
                ->weight('bold'),

            // 2. Show Room Number (Relationship)
            TextColumn::make('room.number')
                ->label('Room')
                ->sortable()
                ->badge() // Makes it look like a tag
                ->color('primary'),

            // 3. Dates
            TextColumn::make('check_in')
                ->date()
                ->sortable(),

            TextColumn::make('check_out')
                ->date()
                ->sortable(),

            // 4. Money
            TextColumn::make('total_price')
                ->money('NGN')
                ->sortable()
                ->weight('bold'),

            // 5. Status
            IconColumn::make('is_paid')
                ->label('Paid?')
                ->boolean()
                ->trueIcon('heroicon-o-check-circle')
                ->falseIcon('heroicon-o-x-circle'),
        ])
        ->defaultSort('created_at', 'desc') // Show newest bookings first
        ->filters([
            // Use this to quickly find unpaid guests
            \Filament\Tables\Filters\TernaryFilter::make('is_paid')
                ->label('Payment Status')
                ->trueLabel('Paid')
                ->falseLabel('Pending'),
        ])
        ->recordActions([
            Action::make('edit')
                ->label('Edit')
                ->icon('heroicon-o-pencil')
                ->url(fn (Booking $record) => EditBooking::getUrl(['record' => $record])),            
            Action::make('delete')
                ->requiresConfirmation()
                ->label('Delete')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->action(fn (Booking $record) => $record->delete()),
        ])
        ->paginated([10, 25, 50, 100])
        ->toolbarActions([
            BulkAction::make('delete')
            ->requiresConfirmation()
            ->label('Delete Selected')
            ->icon('heroicon-o-trash')
            ->action(fn (array $records) => Booking::whereIn('id', $records)->delete()),
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
            'index' => ListBookings::route('/'),
            'create' => CreateBooking::route('/create'),
            'edit' => EditBooking::route('/{record}/edit'),
        ];
    }
}
