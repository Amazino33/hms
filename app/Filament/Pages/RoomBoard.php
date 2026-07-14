<?php

namespace App\Filament\Pages;

use App\Models\Room;
use App\Services\PermissionService;
use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

/**
 * The receptionist home screen — a color-coded overview of every room,
 * tapping through to the folio (which now hosts both check-in and
 * check-out actions, see FolioDetail) or, for a vacant room, the
 * reservations timeline to start a new booking.
 */
class RoomBoard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static string|UnitEnum|null $navigationGroup = 'Hotel';

    protected static ?string $navigationLabel = 'Room Board';

    protected static ?string $title = 'Room Board';

    protected string $view = 'filament.pages.room-board';

    public static function canAccess(): bool
    {
        return PermissionService::canAccessPage(self::class);
    }

    public function getViewData(): array
    {
        $today = now()->toDateString();

        $rooms = Room::with(['bookings' => function ($query) {
            $query->whereIn('status', ['reserved', 'checked_in'])->with('guest');
        }])->orderBy('number')->get();

        $tiles = $rooms->map(function (Room $room) use ($today) {
            $occupancy = $room->status === 'maintenance' ? 'maintenance' : $room->occupancyState();

            $booking = $room->bookings->first(function ($b) use ($today) {
                if ($b->status === 'checked_in') {
                    return $b->check_in->toDateString() <= $today && $b->check_out->toDateString() >= $today;
                }

                return $b->status === 'reserved' && $b->check_in->toDateString() === $today;
            });

            return [
                'room' => $room,
                'booking' => $booking,
                'occupancy' => $occupancy,
            ];
        });

        return ['tiles' => $tiles];
    }
}
