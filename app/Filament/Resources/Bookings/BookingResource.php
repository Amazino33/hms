<?php

namespace App\Filament\Resources\Bookings;

use App\Filament\Resources\Bookings\Pages\CreateBooking;
use App\Filament\Resources\Bookings\Pages\EditBooking;
use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Filament\Resources\Bookings\Schemas\BookingForm;
use App\Filament\Resources\Bookings\Tables\BookingsTable;
use App\Models\Booking;
use BackedEnum;
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

class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $recordTitleAttribute = 'name';

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
                ->options(Room::where('status', 'available')->pluck('number', 'id'))
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

        if ($roomId && $checkIn && $checkOut) {
            $room = Room::find($roomId);
            $days = Carbon::parse($checkIn)->diffInDays(Carbon::parse($checkOut));
            $days = $days < 1 ? 1 : $days; // Minimum 1 night
            
            $set('total_price', $room->price_per_night * $days);
        }
    }

    public static function table(Table $table): Table
    {
        return BookingsTable::configure($table);
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
