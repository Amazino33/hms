<?php

use App\Models\Attendance\AttendanceShiftAssignment;
use App\Models\Attendance\AttendanceShiftTemplate;
use App\Models\User;
use App\Services\Attendance\HandoverCoverageChecker;
use App\Services\Attendance\ShiftAssignmentService;
use App\Services\Attendance\ShiftTemplateService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->templates = app(ShiftTemplateService::class);
    $this->assignments = app(ShiftAssignmentService::class);
    $this->actor = User::factory()->create();
});

function dayTemplate(ShiftTemplateService $service, ?User $actor = null): AttendanceShiftTemplate
{
    return $service->create([
        'name' => 'Day shift',
        'start_time' => '08:00',
        'duration_minutes' => 600,
        'pattern_type' => 'weekly',
        'weekly_days' => [1, 2, 3, 4, 5, 6, 7],
    ], $actor);
}

it('locks the timing fields once anyone is scheduled on the template', function () {
    $template = dayTemplate($this->templates, $this->actor);
    $user = User::factory()->create();

    // Editable while unused.
    $this->templates->update($template, ['start_time' => '09:00'], $this->actor);
    expect($template->fresh()->startTimeString())->toBe('09:00:00');

    $this->assignments->assign($user, $template, CarbonImmutable::parse('2026-03-01'), actor: $this->actor);

    expect(fn () => $this->templates->update($template->fresh(), ['start_time' => '10:00'], $this->actor))
        ->toThrow(ValidationException::class);

    // And the stored value is untouched, not merely rejected at the form.
    expect($template->fresh()->startTimeString())->toBe('09:00:00');
});

it('still allows renaming a locked template', function () {
    $template = dayTemplate($this->templates, $this->actor);
    $user = User::factory()->create();
    $this->assignments->assign($user, $template, CarbonImmutable::parse('2026-03-01'), actor: $this->actor);

    // A label is not a basis for a fine, so it stays editable.
    $this->templates->update($template->fresh(), ['name' => 'Morning shift'], $this->actor);

    expect($template->fresh()->name)->toBe('Morning shift');
});

it('duplicate and replace closes open assignments and reopens them on the new template', function () {
    $template = dayTemplate($this->templates, $this->actor);
    $user = User::factory()->create();

    $old = $this->assignments->assign(
        $user,
        $template,
        CarbonImmutable::parse('2026-03-01'),
        weeklyDaysOverride: [1, 3, 5],
        actor: $this->actor,
    );

    $new = $this->templates->duplicateAndReplace($template, [
        'start_time' => '09:00',
        'duration_minutes' => 540,
        'pattern_type' => 'weekly',
        'weekly_days' => [1, 2, 3, 4, 5, 6, 7],
    ], CarbonImmutable::parse('2026-04-01'), $this->actor);

    $old->refresh();
    expect($old->effective_to->toDateString())->toBe('2026-03-31');

    $replacement = AttendanceShiftAssignment::where('attendance_shift_template_id', $new->id)->sole();
    expect($replacement->effective_from->toDateString())->toBe('2026-04-01');
    // The person's own day override must survive the swap, or their rota
    // silently widens to the template's full week.
    expect($replacement->weekly_days_override)->toBe([1, 3, 5]);
    expect($template->fresh()->isRetired())->toBeTrue();
});

it('carries the rotation anchor across a duplicate and replace', function () {
    $template = $this->templates->create([
        'name' => 'Bartender 24h',
        'start_time' => '08:00',
        'duration_minutes' => 1440,
        'pattern_type' => 'rotation',
        'rotation_on_days' => 1,
        'rotation_off_days' => 1,
        'is_handover' => true,
    ], $this->actor);

    $user = User::factory()->create();
    $this->assignments->assign(
        $user,
        $template,
        CarbonImmutable::parse('2026-03-01'),
        rotationAnchorDate: CarbonImmutable::parse('2026-03-02'),
        actor: $this->actor,
    );

    $new = $this->templates->duplicateAndReplace($template, [
        'start_time' => '07:00',
        'duration_minutes' => 1440,
        'pattern_type' => 'rotation',
        'rotation_on_days' => 1,
        'rotation_off_days' => 1,
    ], CarbonImmutable::parse('2026-04-01'), $this->actor);

    $replacement = AttendanceShiftAssignment::where('attendance_shift_template_id', $new->id)->sole();
    expect($replacement->rotation_anchor_date->toDateString())->toBe('2026-03-02');
});

it('closes the previous assignment the day before a schedule change', function () {
    $template = dayTemplate($this->templates, $this->actor);
    $other = $this->templates->create([
        'name' => 'Weekends',
        'start_time' => '12:00',
        'duration_minutes' => 480,
        'pattern_type' => 'weekly',
        'weekly_days' => [6, 7],
    ], $this->actor);

    $user = User::factory()->create();
    $first = $this->assignments->assign($user, $template, CarbonImmutable::parse('2026-03-01'), actor: $this->actor);
    $this->assignments->assign($user, $other, CarbonImmutable::parse('2026-03-15'), actor: $this->actor);

    expect($first->fresh()->effective_to->toDateString())->toBe('2026-03-14');
    expect(AttendanceShiftAssignment::where('user_id', $user->id)->count())->toBe(2);
});

it('never leaves two assignments covering the same day', function () {
    $template = dayTemplate($this->templates, $this->actor);
    $user = User::factory()->create();

    $this->assignments->assign($user, $template, CarbonImmutable::parse('2026-03-01'), actor: $this->actor);
    $this->assignments->assign($user, $template, CarbonImmutable::parse('2026-03-15'), actor: $this->actor);
    $this->assignments->assign($user, $template, CarbonImmutable::parse('2026-04-01'), actor: $this->actor);

    // Walk every day and assert at most one assignment claims it.
    for ($d = CarbonImmutable::parse('2026-02-20'); $d->lte(CarbonImmutable::parse('2026-04-10')); $d = $d->addDay()) {
        $covering = AttendanceShiftAssignment::where('user_id', $user->id)->covering($d)->count();
        expect($covering)->toBeLessThanOrEqual(1, 'Two assignments cover '.$d->toDateString());
    }
});

it('rejects a rotation assignment with no anchor date', function () {
    $rotation = $this->templates->create([
        'name' => 'Rotation',
        'start_time' => '08:00',
        'duration_minutes' => 1440,
        'pattern_type' => 'rotation',
        'rotation_on_days' => 1,
        'rotation_off_days' => 1,
    ], $this->actor);

    $user = User::factory()->create();

    expect(fn () => $this->assignments->assign($user, $rotation, CarbonImmutable::parse('2026-03-01'), actor: $this->actor))
        ->toThrow(ValidationException::class);

    expect(AttendanceShiftAssignment::count())->toBe(0);
});

it('refuses to assign anyone to a retired template', function () {
    $template = dayTemplate($this->templates, $this->actor);
    $this->templates->retire($template, $this->actor);

    expect(fn () => $this->assignments->assign(User::factory()->create(), $template->fresh(), CarbonImmutable::parse('2026-03-01'), actor: $this->actor))
        ->toThrow(ValidationException::class);
});

it('rejects a weekly template with no days, which would schedule nobody ever', function () {
    expect(fn () => $this->templates->create([
        'name' => 'Nothing',
        'start_time' => '08:00',
        'duration_minutes' => 600,
        'pattern_type' => 'weekly',
        'weekly_days' => [],
    ], $this->actor))->toThrow(ValidationException::class);
});

it('drops an override that merely repeats the template days', function () {
    $template = dayTemplate($this->templates, $this->actor);
    $user = User::factory()->create();

    $assignment = $this->assignments->assign(
        $user,
        $template,
        CarbonImmutable::parse('2026-03-01'),
        weeklyDaysOverride: [7, 6, 5, 4, 3, 2, 1],
        actor: $this->actor,
    );

    // Stored as null, so a later change to the template still reaches this
    // person instead of being shadowed by a redundant copy.
    expect($assignment->weekly_days_override)->toBeNull();
});

it('flags gaps and double-ups on a handover rota', function () {
    $rotation = $this->templates->create([
        'name' => 'Bartender 24h',
        'start_time' => '08:00',
        'duration_minutes' => 1440,
        'pattern_type' => 'rotation',
        'rotation_on_days' => 1,
        'rotation_off_days' => 1,
        'is_handover' => true,
    ], $this->actor);

    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'Bob']);

    // Both anchored on the same day: they double up every other day and
    // nobody covers the days in between.
    $this->assignments->assign($alice, $rotation, CarbonImmutable::parse('2026-03-01'), rotationAnchorDate: CarbonImmutable::parse('2026-03-02'), actor: $this->actor);
    $this->assignments->assign($bob, $rotation, CarbonImmutable::parse('2026-03-01'), rotationAnchorDate: CarbonImmutable::parse('2026-03-02'), actor: $this->actor);

    $result = app(HandoverCoverageChecker::class)->check($rotation, 14, CarbonImmutable::parse('2026-03-02'));

    expect($result['gaps'])->toContain('2026-03-03');
    expect($result['overlaps'][0]['date'])->toBe('2026-03-02');
    expect($result['overlaps'][0]['names'])->toContain('Alice', 'Bob');
    expect(app(HandoverCoverageChecker::class)->hasProblems($result))->toBeTrue();
});

it('reports a correctly anchored handover rota as clean', function () {
    $rotation = $this->templates->create([
        'name' => 'Bartender 24h',
        'start_time' => '08:00',
        'duration_minutes' => 1440,
        'pattern_type' => 'rotation',
        'rotation_on_days' => 1,
        'rotation_off_days' => 1,
        'is_handover' => true,
    ], $this->actor);

    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'Bob']);

    $this->assignments->assign($alice, $rotation, CarbonImmutable::parse('2026-03-01'), rotationAnchorDate: CarbonImmutable::parse('2026-03-02'), actor: $this->actor);
    $this->assignments->assign($bob, $rotation, CarbonImmutable::parse('2026-03-01'), rotationAnchorDate: CarbonImmutable::parse('2026-03-03'), actor: $this->actor);

    $result = app(HandoverCoverageChecker::class)->check($rotation, 14, CarbonImmutable::parse('2026-03-02'));

    expect(app(HandoverCoverageChecker::class)->hasProblems($result))->toBeFalse();
});

it('lists non-exempt staff with no current schedule', function () {
    $template = dayTemplate($this->templates, $this->actor);

    $scheduled = User::factory()->create(['name' => 'Scheduled']);
    $unscheduled = User::factory()->create(['name' => 'Unscheduled']);
    $exempt = User::factory()->create(['name' => 'Owner', 'attendance_exempt' => true]);
    $leaver = User::factory()->create(['name' => 'Leaver', 'left_at' => now()]);

    $this->assignments->assign($scheduled, $template, CarbonImmutable::now()->subMonth(), actor: $this->actor);

    $names = $this->assignments->staffWithoutSchedule()->pluck('name');

    expect($names)->toContain('Unscheduled');
    expect($names)->not->toContain('Scheduled');
    expect($names)->not->toContain('Owner');
    expect($names)->not->toContain('Leaver');
});
