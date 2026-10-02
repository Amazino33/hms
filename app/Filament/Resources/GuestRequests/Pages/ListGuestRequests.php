<?php

namespace App\Filament\Resources\GuestRequests\Pages;

use App\Filament\Resources\GuestRequests\GuestRequestResource;
use Filament\Resources\Pages\ListRecords;

/** Read-only: no header actions. */
class ListGuestRequests extends ListRecords
{
    protected static string $resource = GuestRequestResource::class;
}
