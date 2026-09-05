<?php

namespace App\Filament\Resources\Announcements\Pages;

use App\Filament\Resources\Announcements\AnnouncementResource;
use Filament\Resources\Pages\EditRecord;

class EditAnnouncement extends EditRecord
{
    protected static string $resource = AnnouncementResource::class;

    /** @var array<int, string> */
    protected array $targetRoles = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['target_roles'] = $this->record->targets->pluck('role_name')->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->targetRoles = $data['target_roles'] ?? [];
        unset($data['target_roles']);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->syncTargetRoles($this->targetRoles);
    }
}
