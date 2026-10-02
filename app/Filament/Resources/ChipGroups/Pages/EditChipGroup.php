<?php

namespace App\Filament\Resources\ChipGroups\Pages;

use App\Filament\Resources\ChipGroups\ChipGroupResource;
use Filament\Resources\Pages\EditRecord;

/**
 * Deliberately no DeleteAction — a chip group is switched off with its
 * "active" toggle, never deleted.
 */
class EditChipGroup extends EditRecord
{
    protected static string $resource = ChipGroupResource::class;

    /** The category links save after the group itself — drop the guest menu cache once they have. */
    protected function afterSave(): void
    {
        \App\Services\Guest\GuestMenuService::forgetMenuCache();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
