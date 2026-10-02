<?php

namespace App\Filament\Resources\ChipGroups\Pages;

use App\Filament\Resources\ChipGroups\ChipGroupResource;
use Filament\Resources\Pages\CreateRecord;

class CreateChipGroup extends CreateRecord
{
    protected static string $resource = ChipGroupResource::class;

    /** The category links save after the group itself — drop the guest menu cache once they have. */
    protected function afterCreate(): void
    {
        \App\Services\Guest\GuestMenuService::forgetMenuCache();
    }
}
