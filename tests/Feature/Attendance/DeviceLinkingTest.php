<?php

use App\Models\Attendance\AttendanceDeviceLink;
use App\Models\Attendance\AttendanceDeviceUser;
use App\Models\AttendanceLog;
use App\Models\User;
use App\Services\Attendance\DeviceLinkService;
use Carbon\CarbonImmutable;
use Database\Seeders\ShieldSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(ShieldSeeder::class);
    $this->service = app(DeviceLinkService::class);
    $this->actor = User::factory()->create();
});

function deviceUser(string $id = '7', ?string $name = 'Mary'): AttendanceDeviceUser
{
    return AttendanceDeviceUser::create(['device_user_id' => $id, 'device_name' => $name]);
}

function punch(string $badge, string $utc, ?int $userId = null): AttendanceLog
{
    return AttendanceLog::create([
        'user_id' => $userId,
        'biometric_id' => $badge,
        'punch_time' => CarbonImmutable::parse($utc, 'UTC'),
    ]);
}

it('enforces one unique device user id', function () {
    deviceUser('7');

    expect(fn () => deviceUser('7'))->toThrow(Illuminate\Database\QueryException::class);
});

it('refuses a second active link on one device user', function () {
    $device = deviceUser();
    $this->service->link($device, User::factory()->create(), actor: $this->actor);

    expect(fn () => $this->service->link($device->refresh(), User::factory()->create(), actor: $this->actor))
        ->toThrow(ValidationException::class);

    expect(AttendanceDeviceLink::count())->toBe(1);
});

it('never lets a retired device id be linked again to anyone', function () {
    $device = deviceUser();
    $link = $this->service->link($device, User::factory()->create(), CarbonImmutable::parse('2026-01-01'), $this->actor);

    $this->service->end($link, CarbonImmutable::parse('2026-05-31'), $this->actor, 'Left the company');

    expect($device->refresh()->isRetired())->toBeTrue();

    // Not the same person, and not anybody else either — the device reuses
    // IDs, so a new starter on this ID must get a new enrolment.
    expect(fn () => $this->service->link($device->refresh(), User::factory()->create(), actor: $this->actor))
        ->toThrow(ValidationException::class);
});

it('sets and clears users.biometric_id as the single writer', function () {
    $device = deviceUser('12');
    $user = User::factory()->create();

    $link = $this->service->link($device, $user, actor: $this->actor);
    expect($user->fresh()->biometric_id)->toBe('12');

    $this->service->end($link, now(), $this->actor);
    expect($user->fresh()->biometric_id)->toBeNull();
});

it('leaves a leaver\'s punches attributed to them when the link merely ends', function () {
    $device = deviceUser('12');
    $user = User::factory()->create();

    $link = $this->service->link($device, $user, CarbonImmutable::parse('2026-03-01'), $this->actor);
    $log = punch('12', '2026-03-10 07:00:00', $user->id);

    $this->service->end($link, CarbonImmutable::parse('2026-03-31'), $this->actor, 'Resigned');

    // They really did make those punches; ending is not a correction.
    expect($log->fresh()->user_id)->toBe($user->id);
});

it('excludes a voided link from active-link lookups and allows a relink', function () {
    $device = deviceUser('12');
    $wrong = User::factory()->create(['name' => 'Wrong Person']);
    $right = User::factory()->create(['name' => 'Right Person']);

    $link = $this->service->link($device, $wrong, CarbonImmutable::parse('2026-03-01'), $this->actor);

    $result = $this->service->void($link, 'Linked the wrong Victor', $this->actor, $right, CarbonImmutable::parse('2026-03-01'));

    expect($link->fresh()->isVoided())->toBeTrue();
    expect($device->refresh()->activeLink()->user_id)->toBe($right->id);
    expect($result['replacement']->effective_from->toDateString())->toBe('2026-03-01');
});

it('requires a reason to void', function () {
    $device = deviceUser();
    $link = $this->service->link($device, User::factory()->create(), actor: $this->actor);

    expect(fn () => $this->service->void($link, '   ', $this->actor))
        ->toThrow(ValidationException::class);

    expect($link->fresh()->isVoided())->toBeFalse();
});

it('re-attributes punches inside the voided window to the replacement', function () {
    $device = deviceUser('12');
    $wrong = User::factory()->create();
    $right = User::factory()->create();

    // An open link, so the window runs from effective_from to today.
    $link = $this->service->link($device, $wrong, CarbonImmutable::parse('2026-03-01'), $this->actor);

    $inside = punch('12', '2026-03-10 07:00:00', $wrong->id);

    $result = $this->service->void($link->fresh(), 'Wrong person', $this->actor, $right, CarbonImmutable::parse('2026-03-01'));

    expect($inside->fresh()->user_id)->toBe($right->id);
    expect($result['punches_reattributed'])->toBe(1);
});

/**
 * Retirement survives a void. Voiding says the LINK was a mistake; it says
 * nothing about the badge being finished with, and the device reuses IDs — so
 * the one rule that must never bend is that a retired ID is never linked
 * again. The punches still get corrected; only the relink is refused.
 */
it('still refuses a replacement link on a badge that was retired', function () {
    $device = deviceUser('12');
    $wrong = User::factory()->create();
    $right = User::factory()->create();

    $link = $this->service->link($device, $wrong, CarbonImmutable::parse('2026-03-01'), $this->actor);
    $this->service->end($link, CarbonImmutable::parse('2026-03-31'), $this->actor, 'Left');

    expect(fn () => $this->service->void($link->fresh(), 'Wrong person', $this->actor, $right))
        ->toThrow(ValidationException::class);

    // And the refusal rolls back the void itself, rather than half-applying.
    expect($link->fresh()->isVoided())->toBeFalse();
});

it('still corrects punches when voiding a retired badge with no replacement', function () {
    $device = deviceUser('12');
    $wrong = User::factory()->create();

    $link = $this->service->link($device, $wrong, CarbonImmutable::parse('2026-03-01'), $this->actor);
    $inside = punch('12', '2026-03-10 07:00:00', $wrong->id);
    $this->service->end($link, CarbonImmutable::parse('2026-03-31'), $this->actor, 'Left');

    $result = $this->service->void($link->fresh(), 'Was never this person', $this->actor);

    expect($inside->fresh()->user_id)->toBeNull();
    expect($result['punches_reattributed'])->toBe(1);
});

it('nulls the punches when a void has no replacement', function () {
    $device = deviceUser('12');
    $wrong = User::factory()->create();

    $link = $this->service->link($device, $wrong, CarbonImmutable::parse('2026-03-01'), $this->actor);
    $log = punch('12', '2026-03-10 07:00:00', $wrong->id);

    $this->service->void($link, 'Never should have been linked', $this->actor);

    expect($log->fresh()->user_id)->toBeNull();
});

it('leaves punches outside the voided date range untouched', function () {
    $device = deviceUser('12');
    $wrong = User::factory()->create();
    $right = User::factory()->create();

    // A historical closed link, written directly: end() would also retire the
    // badge, and a retired badge cannot take the replacement this asserts on.
    $link = AttendanceDeviceLink::create([
        'attendance_device_user_id' => $device->id,
        'user_id' => $wrong->id,
        'effective_from' => '2026-03-01',
        'effective_to' => '2026-03-31',
    ]);
    $wrong->forceFill(['biometric_id' => '12'])->save();

    $before = punch('12', '2026-02-20 07:00:00', $wrong->id);
    $after = punch('12', '2026-04-05 07:00:00', $wrong->id);
    $inside = punch('12', '2026-03-15 07:00:00', $wrong->id);

    $this->service->void($link->fresh(), 'Wrong person', $this->actor, $right, CarbonImmutable::parse('2026-03-01'));

    expect($before->fresh()->user_id)->toBe($wrong->id);
    expect($after->fresh()->user_id)->toBe($wrong->id);
    expect($inside->fresh()->user_id)->toBe($right->id);
});

it('leaves other badges untouched when voiding', function () {
    $device = deviceUser('12');
    $other = deviceUser('99', 'Someone Else');
    $wrong = User::factory()->create();
    $right = User::factory()->create();
    $bystander = User::factory()->create();

    $link = $this->service->link($device, $wrong, CarbonImmutable::parse('2026-03-01'), $this->actor);
    $this->service->link($other, $bystander, CarbonImmutable::parse('2026-03-01'), $this->actor);

    $mine = punch('12', '2026-03-10 07:00:00', $wrong->id);
    $theirs = punch('99', '2026-03-10 07:00:00', $bystander->id);

    $this->service->void($link->fresh(), 'Wrong person', $this->actor, $right, CarbonImmutable::parse('2026-03-01'));

    expect($mine->fresh()->user_id)->toBe($right->id);
    expect($theirs->fresh()->user_id)->toBe($bystander->id);
});

it('changes nothing but user_id on an attendance log', function () {
    $device = deviceUser('12');
    $wrong = User::factory()->create();
    $right = User::factory()->create();

    $link = $this->service->link($device, $wrong, CarbonImmutable::parse('2026-03-01'), $this->actor);
    $log = AttendanceLog::create([
        'user_id' => $wrong->id,
        'biometric_id' => '12',
        'punch_time' => CarbonImmutable::parse('2026-03-10 07:00:00', 'UTC'),
        'punch_state' => '0',
        'verify_mode' => 1,
    ]);

    $before = DB::table('attendance_logs')->where('id', $log->id)->first();

    $this->service->void($link, 'Wrong person', $this->actor, $right, CarbonImmutable::parse('2026-03-01'));

    $after = DB::table('attendance_logs')->where('id', $log->id)->first();

    expect($after->user_id)->toBe($right->id);
    expect($after->biometric_id)->toBe($before->biometric_id);
    expect($after->punch_time)->toBe($before->punch_time);
    expect($after->punch_state)->toBe($before->punch_state);
    expect($after->verify_mode)->toBe($before->verify_mode);
});

it('reports legacy late fines over the affected dates without altering them', function () {
    $device = deviceUser('12');
    $wrong = User::factory()->create();

    $link = $this->service->link($device, $wrong, CarbonImmutable::parse('2026-03-01'), $this->actor);
    punch('12', '2026-03-10 07:00:00', $wrong->id);

    $deduction = \App\Models\SalaryDeduction::create([
        'user_id' => $wrong->id,
        'amount' => 500,
        'date' => '2026-03-10',
        'reason' => 'Lateness fee. Expected: 08:00, Arrived: 08:20',
    ]);

    $result = $this->service->void($link, 'Wrong person', $this->actor);

    // Surfaced for a human, never reversed automatically — taking money back
    // off somebody is not a side effect of a clerical correction.
    expect($result['affected_deductions'])->toHaveCount(1);
    expect($result['affected_deductions']->first()->id)->toBe($deduction->id);
    expect(\App\Models\SalaryDeduction::find($deduction->id))->not->toBeNull();
    expect(\App\Models\SalaryDeduction::find($deduction->id)->amount)->toEqual($deduction->amount);
});

it('rolls back the link and the logs together when the void fails', function () {
    $device = deviceUser('12');
    $wrong = User::factory()->create();
    $right = User::factory()->create(['biometric_id' => '55']);

    $link = $this->service->link($device, $wrong, CarbonImmutable::parse('2026-03-01'), $this->actor);
    $log = punch('12', '2026-03-10 07:00:00', $wrong->id);

    // The replacement already holds another badge, which link() refuses —
    // mid-transaction, after the void has been written.
    expect(fn () => $this->service->void($link, 'Wrong person', $this->actor, $right, CarbonImmutable::parse('2026-03-01')))
        ->toThrow(ValidationException::class);

    expect($link->fresh()->isVoided())->toBeFalse();
    expect($log->fresh()->user_id)->toBe($wrong->id);
    expect($wrong->fresh()->biometric_id)->toBe('12');
});

it('logs one entry carrying the link ids, user ids and row count', function () {
    $device = deviceUser('12');
    $wrong = User::factory()->create();
    $right = User::factory()->create();

    $link = $this->service->link($device, $wrong, CarbonImmutable::parse('2026-03-01'), $this->actor);
    punch('12', '2026-03-10 07:00:00', $wrong->id);
    punch('12', '2026-03-11 07:00:00', $wrong->id);

    $this->service->void($link, 'Wrong Victor', $this->actor, $right, CarbonImmutable::parse('2026-03-01'));

    $entry = Activity::where('log_name', 'attendance_device_link')
        ->where('description', 'like', 'Voided device link%')
        ->sole();

    expect($entry->causer_id)->toBe($this->actor->id);
    expect($entry->properties['voided_link_id'])->toBe($link->id);
    expect($entry->properties['from_user_id'])->toBe($wrong->id);
    expect($entry->properties['to_user_id'])->toBe($right->id);
    expect($entry->properties['punches_reattributed'])->toBe(2);
    expect($entry->properties['reason'])->toBe('Wrong Victor');
});

it('lists only device users with no active link as unmatched', function () {
    $linked = deviceUser('1', 'Linked');
    $unlinked = deviceUser('2', 'Unlinked');
    $retired = deviceUser('3', 'Retired');
    $voided = deviceUser('4', 'Voided');

    $this->service->link($linked, User::factory()->create(), actor: $this->actor);

    $retiredLink = $this->service->link($retired, User::factory()->create(), actor: $this->actor);
    $this->service->end($retiredLink, now(), $this->actor);

    $voidedLink = $this->service->link($voided, User::factory()->create(), actor: $this->actor);
    $this->service->void($voidedLink, 'Mistake', $this->actor);

    $unmatched = AttendanceDeviceUser::unmatched()->pluck('device_user_id');

    expect($unmatched)->toContain('2');
    // A voided link leaves the badge needing attention again.
    expect($unmatched)->toContain('4');
    expect($unmatched)->not->toContain('1');
    // Retired badges are finished with, not waiting to be matched.
    expect($unmatched)->not->toContain('3');
});

it('creates an attendance-only staff member and the link atomically', function () {
    $device = deviceUser('25', 'Annie');

    $link = $this->service->createStaffAndLink($device, 'Annie Okon', 'Cleaner', CarbonImmutable::parse('2026-03-01'), $this->actor);

    $user = $link->user;
    expect($user->name)->toBe('Annie Okon');
    expect($user->job_title)->toBe('Cleaner');
    expect($user->hasRole(User::ATTENDANCE_ONLY_ROLE))->toBeTrue();
    expect($user->biometric_id)->toBe('25');
    expect($user->password)->toBeNull();
});

it('leaves neither user nor link behind when create-and-link fails', function () {
    $device = deviceUser('25', 'Annie');
    $this->service->link($device, User::factory()->create(), actor: $this->actor);

    $usersBefore = User::count();

    // Already linked, so the link step throws after the user would have been
    // created — and must take the user with it.
    expect(fn () => $this->service->createStaffAndLink($device->refresh(), 'Annie Okon', 'Cleaner', null, $this->actor))
        ->toThrow(ValidationException::class);

    expect(User::count())->toBe($usersBefore);
    expect(AttendanceDeviceLink::count())->toBe(1);
});

it('refuses to put one person on two badges at once', function () {
    $first = deviceUser('1', 'A');
    $second = deviceUser('2', 'B');
    $user = User::factory()->create();

    $this->service->link($first, $user, actor: $this->actor);

    expect(fn () => $this->service->link($second, $user->fresh(), actor: $this->actor))
        ->toThrow(ValidationException::class);
});

it('suggests a close name match but never links on it', function () {
    User::factory()->create(['name' => 'Jessica Gaius']);
    $device = deviceUser('32', 'Jessica');

    $match = $device->suggestedMatch();

    expect($match['user']->name)->toBe('Jessica Gaius');
    // A suggestion is a hint for the admin's eyes. Nothing is linked.
    expect($device->activeLink())->toBeNull();
});
