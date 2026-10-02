<?php

namespace App\Filament\Resources\GuestRequests\Pages;

use App\Filament\Resources\GuestRequests\GuestRequestResource;
use Filament\Resources\Pages\ViewRecord;

/** Read-only: no edit or delete actions. */
class ViewGuestRequest extends ViewRecord
{
    protected static string $resource = GuestRequestResource::class;
}
