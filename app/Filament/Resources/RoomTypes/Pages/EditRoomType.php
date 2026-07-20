<?php

namespace App\Filament\Resources\RoomTypes\Pages;

use App\Filament\Resources\RoomTypes\RoomTypeResource;
use Filament\Resources\Pages\EditRecord;

/**
 * Deliberately no DeleteAction here — room types are never deleted, only
 * deactivated via the is_active toggle on this same form, so a Room always
 * keeps a valid type reference.
 */
class EditRoomType extends EditRecord
{
    protected static string $resource = RoomTypeResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
