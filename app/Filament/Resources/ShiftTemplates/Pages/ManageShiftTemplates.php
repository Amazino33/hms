<?php

namespace App\Filament\Resources\ShiftTemplates\Pages;

use App\Filament\Resources\ShiftTemplates\ShiftTemplateResource;
use App\Services\Attendance\ShiftTemplateService;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Validation\ValidationException;

/**
 * Create and edit both route through ShiftTemplateService so the locking rule
 * and the pattern-coherence checks apply to the form exactly as they apply to
 * anything else — a disabled input is a convenience, not a guarantee.
 */
class ManageShiftTemplates extends ManageRecords
{
    protected static string $resource = ShiftTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @throws ValidationException
     */
    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        return app(ShiftTemplateService::class)->create(
            static::normalise($data),
            auth()->user(),
        );
    }

    /**
     * @throws ValidationException
     */
    protected function handleRecordUpdate(\Illuminate\Database\Eloquent\Model $record, array $data): \Illuminate\Database\Eloquent\Model
    {
        return app(ShiftTemplateService::class)->update(
            $record,
            static::normalise($data),
            auth()->user(),
        );
    }

    /**
     * The form collects hours because that is how a person describes a shift;
     * the column stores minutes because that is what arithmetic needs.
     */
    protected static function normalise(array $data): array
    {
        if (array_key_exists('duration_hours', $data)) {
            $data['duration_minutes'] = (int) round(((float) $data['duration_hours']) * 60);
            unset($data['duration_hours']);
        }

        if (($data['pattern_type'] ?? null) === 'weekly') {
            $data['rotation_on_days'] = null;
            $data['rotation_off_days'] = null;
        }

        if (($data['pattern_type'] ?? null) === 'rotation') {
            $data['weekly_days'] = null;
        }

        return $data;
    }
}
