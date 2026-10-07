<?php

namespace App\Filament\Resources\DeviceUsers\Pages;

use App\Filament\Resources\DeviceUsers\DeviceUserResource;
use Filament\Resources\Pages\ManageRecords;

class ManageDeviceUsers extends ManageRecords
{
    protected static string $resource = DeviceUserResource::class;

    /**
     * Nothing to create by hand: badges arrive from the terminal or from the
     * import, and inventing one here would produce a device ID the device has
     * never heard of.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
