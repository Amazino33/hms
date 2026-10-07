<?php

namespace App\Services\Attendance;

use App\Models\Attendance\AttendanceShiftAssignment;
use App\Models\Attendance\AttendanceShiftTemplate;
use App\Models\User;
use App\Support\VenueTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creating, locking and replacing shift templates.
 *
 * The locking rule is the point of this class. Once anyone is scheduled on a
 * template, editing its times in place would retroactively change who was
 * late on every day already worked under it — a fine raised in March would
 * silently acquire a different basis. So the timing fields freeze, and
 * changing them becomes a dated replacement instead.
 */
class ShiftTemplateService
{
    /**
     * Fields that freeze once any assignment references the template.
     * Name and handover status stay editable: correcting a typo in a label,
     * or the fact that a shift is a relief shift, changes nothing about when
     * somebody was due in.
     */
    public const LOCKED_FIELDS = [
        'start_time',
        'duration_minutes',
        'pattern_type',
        'weekly_days',
        'rotation_on_days',
        'rotation_off_days',
    ];

    public function create(array $attributes, ?User $actor = null): AttendanceShiftTemplate
    {
        $this->assertPatternIsCoherent($attributes);

        return AttendanceShiftTemplate::create($this->normalise($attributes) + [
            'created_by' => $actor?->id,
        ]);
    }

    /**
     * Apply an edit, refusing any change to a frozen field.
     *
     * Enforced here rather than only by disabling the inputs: a disabled
     * field is a UI convenience, and this rule protects fines that have
     * already been issued.
     *
     * @throws ValidationException
     */
    public function update(AttendanceShiftTemplate $template, array $attributes, ?User $actor = null): AttendanceShiftTemplate
    {
        $attributes = $this->normalise($attributes);

        if ($template->isLocked()) {
            $changed = $this->lockedFieldsChanged($template, $attributes);

            if ($changed !== []) {
                throw ValidationException::withMessages([
                    'start_time' => 'This template is in use by '.$template->assignments()->count()
                        .' schedule assignment(s), so its timing cannot be edited ('
                        .implode(', ', $changed).'). Use "Duplicate & replace" to change it from a chosen date.',
                ]);
            }

            // Drop the frozen keys entirely so an unchanged value in the
            // payload cannot mark the model dirty.
            $attributes = array_diff_key($attributes, array_flip(self::LOCKED_FIELDS));
        } else {
            $this->assertPatternIsCoherent(array_merge($template->only(self::LOCKED_FIELDS), $attributes));
        }

        $template->fill($attributes)->save();

        return $template;
    }

    /**
     * Replace a locked template from a given date.
     *
     * Creates the new template, then for every assignment still open on the
     * old one: closes it the day before the changeover and opens an
     * equivalent on the new template, carrying the same day override and
     * rotation anchor so nobody's pattern shifts by accident. One
     * transaction — a half-migrated roster would have people both scheduled
     * twice and not at all.
     */
    public function duplicateAndReplace(
        AttendanceShiftTemplate $old,
        array $attributes,
        CarbonInterface $effectiveFrom,
        ?User $actor = null,
    ): AttendanceShiftTemplate {
        $changeover = CarbonImmutable::parse($effectiveFrom->format('Y-m-d'), VenueTime::TIMEZONE);

        return DB::transaction(function () use ($old, $attributes, $changeover, $actor) {
            $new = $this->create($attributes + [
                'name' => $old->name,
                'is_handover' => (bool) $old->is_handover,
            ], $actor);

            $open = AttendanceShiftAssignment::query()
                ->where('attendance_shift_template_id', $old->id)
                ->open()
                ->get();

            foreach ($open as $assignment) {
                // An assignment that has not started yet is simply moved
                // across; closing it the day before the changeover would
                // leave a backwards range (effective_to < effective_from).
                if ($changeover->lessThanOrEqualTo(CarbonImmutable::parse($assignment->effective_from->format('Y-m-d')))) {
                    $assignment->forceFill(['attendance_shift_template_id' => $new->id])->save();

                    continue;
                }

                $assignment->forceFill([
                    'effective_to' => $changeover->subDay()->toDateString(),
                    'ended_by' => $actor?->id,
                ])->save();

                AttendanceShiftAssignment::create([
                    'user_id' => $assignment->user_id,
                    'attendance_shift_template_id' => $new->id,
                    'effective_from' => $changeover->toDateString(),
                    'weekly_days_override' => $assignment->weekly_days_override,
                    'rotation_anchor_date' => $assignment->rotation_anchor_date,
                    'reason' => 'Template replaced: '.$old->name.' (#'.$old->id.')',
                    'created_by' => $actor?->id,
                ]);
            }

            $this->retire($old, $actor);

            return $new;
        });
    }

    public function retire(AttendanceShiftTemplate $template, ?User $actor = null): void
    {
        if ($template->isRetired()) {
            return;
        }

        $template->forceFill(['retired_at' => now()])->save();

        activity('attendance_shift_template')
            ->performedOn($template)
            ->causedBy($actor)
            ->log('Retired shift template '.$template->name);
    }

    /**
     * @return array<int, string>
     */
    private function lockedFieldsChanged(AttendanceShiftTemplate $template, array $attributes): array
    {
        $changed = [];

        foreach (self::LOCKED_FIELDS as $field) {
            if (! array_key_exists($field, $attributes)) {
                continue;
            }

            $current = $field === 'start_time'
                ? $template->startTimeString()
                : $template->getAttribute($field);

            if (! $this->sameValue($current, $attributes[$field])) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    private function sameValue(mixed $current, mixed $incoming): bool
    {
        if (is_array($current) || is_array($incoming)) {
            $a = array_map('intval', (array) $current);
            $b = array_map('intval', (array) $incoming);
            sort($a);
            sort($b);

            return $a === $b;
        }

        if ($current instanceof \DateTimeInterface) {
            $current = $current->format('H:i:s');
        }

        return (string) $current === (string) $this->normaliseTimeish($incoming);
    }

    private function normaliseTimeish(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i:s');
        }

        // "08:00" and "08:00:00" are the same instant to a person, and the
        // form sends whichever the picker felt like.
        if (is_string($value) && preg_match('/^\d{2}:\d{2}$/', $value)) {
            return $value.':00';
        }

        return $value;
    }

    private function normalise(array $attributes): array
    {
        if (array_key_exists('start_time', $attributes)) {
            $attributes['start_time'] = $this->normaliseTimeish($attributes['start_time']);
        }

        if (array_key_exists('weekly_days', $attributes) && is_array($attributes['weekly_days'])) {
            $days = array_values(array_unique(array_map('intval', $attributes['weekly_days'])));
            sort($days);
            $attributes['weekly_days'] = $days;
        }

        return $attributes;
    }

    /**
     * A weekly template with no days, or a rotation with no days on, produces
     * a schedule nobody ever works — which in Phase 2 reads as "never
     * scheduled", not "always absent", and so hides a rostering mistake
     * rather than surfacing it.
     *
     * @throws ValidationException
     */
    private function assertPatternIsCoherent(array $attributes): void
    {
        $type = $attributes['pattern_type'] ?? null;

        if ($type === 'weekly') {
            $days = array_filter((array) ($attributes['weekly_days'] ?? []), fn ($d) => $d !== null && $d !== '');

            if ($days === []) {
                throw ValidationException::withMessages([
                    'weekly_days' => 'Choose at least one day of the week, or nobody is ever scheduled on this template.',
                ]);
            }
        }

        if ($type === 'rotation') {
            if ((int) ($attributes['rotation_on_days'] ?? 0) < 1) {
                throw ValidationException::withMessages([
                    'rotation_on_days' => 'A rotation needs at least one day on.',
                ]);
            }

            if ((int) ($attributes['rotation_off_days'] ?? 0) < 0) {
                throw ValidationException::withMessages([
                    'rotation_off_days' => 'Days off cannot be negative.',
                ]);
            }
        }

        if ((int) ($attributes['duration_minutes'] ?? 0) < 1) {
            throw ValidationException::withMessages([
                'duration_minutes' => 'A shift must last at least a minute.',
            ]);
        }
    }
}
