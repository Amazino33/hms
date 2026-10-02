<?php

namespace App\Filament\Resources\GuestDeliveryRefusals\Pages;

use App\Filament\Resources\GuestDeliveryRefusals\GuestDeliveryRefusalResource;
use Filament\Resources\Pages\ListRecords;

/** No create: refusals are recorded by reception on Room Orders. */
class ListGuestDeliveryRefusals extends ListRecords
{
    protected static string $resource = GuestDeliveryRefusalResource::class;
}
