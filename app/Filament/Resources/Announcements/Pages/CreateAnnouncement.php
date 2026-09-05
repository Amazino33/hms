<?php

namespace App\Filament\Resources\Announcements\Pages;

use App\Filament\Resources\Announcements\AnnouncementResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Creating always produces a draft — nothing reaches a screen until
 * someone uses the explicit Publish action, which is also what freezes
 * the roster. Writing and sending are deliberately two separate steps.
 */
class CreateAnnouncement extends CreateRecord
{
    protected static string $resource = AnnouncementResource::class;

    /** @var array<int, string> */
    protected array $targetRoles = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // target_roles is a form-only field with no column behind it; the
        // announcement_targets rows need the record's id, so they are
        // written in afterCreate() instead.
        $this->targetRoles = $data['target_roles'] ?? [];
        unset($data['target_roles']);

        $data['created_by'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->syncTargetRoles($this->targetRoles);
    }
}
