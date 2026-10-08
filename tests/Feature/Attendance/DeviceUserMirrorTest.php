<?php

use App\Models\Attendance\AttendanceDeviceUser;
use App\Models\AttendanceLog;
use App\Models\User;
use App\Services\Attendance\DeviceUserImporter;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * The bridge is gone.
 *
 * Phase 1 wrote names into biometric_enrollments and mirrored them forward
 * with an observer. Phase 2 writes attendance_device_users directly, the
 * observer is removed and the old table is renamed to _legacy — so there is
 * one table and nothing to fall out of step.
 *
 * attendance:reconcile-device-users stays as the net, now reading
 * attendance_logs: a punch is the one thing that cannot be bypassed, because
 * whatever happens to names, a badge that has been used has a row there.
 */
it('creates a device user from a punch the system has never seen', function () {
    AttendanceLog::create([
        'biometric_id' => '55',
        'punch_time' => CarbonImmutable::parse('2026-10-05 07:00:00', 'UTC'),
    ]);

    expect(AttendanceDeviceUser::where('device_user_id', '55')->exists())->toBeFalse();

    $this->artisan('attendance:reconcile-device-users')->assertSuccessful();

    $deviceUser = AttendanceDeviceUser::where('device_user_id', '55')->sole();
    expect($deviceUser->device_name)->toBeNull();
    expect($deviceUser->first_seen_at)->not->toBeNull();
});

it('changes nothing when run a second time', function () {
    AttendanceLog::create(['biometric_id' => '55', 'punch_time' => now()]);
    AttendanceDeviceUser::create(['device_user_id' => '7', 'device_name' => 'Mary']);

    $this->artisan('attendance:reconcile-device-users')->assertSuccessful();

    $snapshot = AttendanceDeviceUser::orderBy('device_user_id')
        ->get(['device_user_id', 'device_name', 'first_seen_at', 'updated_at'])
        ->toArray();

    $this->artisan('attendance:reconcile-device-users')->assertSuccessful();

    expect(AttendanceDeviceUser::orderBy('device_user_id')
        ->get(['device_user_id', 'device_name', 'first_seen_at', 'updated_at'])
        ->toArray())->toBe($snapshot);
});

it('never touches a retired device user', function () {
    $retired = AttendanceDeviceUser::create(['device_user_id' => '7', 'device_name' => 'Original Holder']);
    $retired->forceFill(['retired_at' => now()])->save();

    AttendanceLog::create(['biometric_id' => '7', 'punch_time' => now()]);

    $this->artisan('attendance:reconcile-device-users')->assertSuccessful();

    expect($retired->fresh()->device_name)->toBe('Original Holder');
    expect($retired->fresh()->first_seen_at)->toBeNull();
});

it('stamps first seen from the earliest punch and never moves it afterwards', function () {
    AttendanceLog::create(['biometric_id' => '55', 'punch_time' => CarbonImmutable::parse('2026-10-05 07:00:00', 'UTC')]);
    $this->artisan('attendance:reconcile-device-users');

    $first = AttendanceDeviceUser::sole()->first_seen_at;

    // A punch arriving late from the device's offline buffer must not rewrite
    // a date other records already cite.
    AttendanceLog::create(['biometric_id' => '55', 'punch_time' => CarbonImmutable::parse('2026-09-01 07:00:00', 'UTC')]);
    $this->artisan('attendance:reconcile-device-users');

    expect(AttendanceDeviceUser::sole()->first_seen_at->eq($first))->toBeTrue();
});

it('never creates a link while reconciling', function () {
    User::factory()->create(['biometric_id' => '7']);
    AttendanceLog::create(['biometric_id' => '7', 'punch_time' => now()]);

    $this->artisan('attendance:reconcile-device-users');

    // Pairing is a human decision; reconciliation only ever carries names.
    expect(AttendanceDeviceUser::sole()->activeLink())->toBeNull();
});

it('sets a machine name by hand into the live table', function () {
    $this->artisan('hms:set-machine-name', ['pairs' => ['20', 'Chidi Okeke', '31', 'Ada Nwosu']])
        ->assertSuccessful();

    expect(AttendanceDeviceUser::where('device_user_id', '20')->value('device_name'))->toBe('Chidi Okeke');
    expect(AttendanceDeviceUser::where('device_user_id', '31')->value('device_name'))->toBe('Ada Nwosu');
});

it('refuses to rename a retired badge by hand', function () {
    $retired = AttendanceDeviceUser::create(['device_user_id' => '7', 'device_name' => 'Original']);
    $retired->forceFill(['retired_at' => now()])->save();

    $this->artisan('hms:set-machine-name', ['pairs' => ['7', 'New Starter']])->assertSuccessful();

    // The manual path must not be a way around the retirement rule.
    expect($retired->fresh()->device_name)->toBe('Original');
});

it('rejects an odd number of values rather than guessing which is which', function () {
    $this->artisan('hms:set-machine-name', ['pairs' => ['20', 'Chidi Okeke', '31']])->assertFailed();

    expect(AttendanceDeviceUser::count())->toBe(0);
});

it('imports device users from a csv, upserting by device id', function () {
    AttendanceDeviceUser::create(['device_user_id' => '7', 'device_name' => 'Old Name']);
    AttendanceDeviceUser::create(['device_user_id' => '8', 'device_name' => 'Unchanged']);

    $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
    file_put_contents($path, "device_user_id,device_name\n7,Mary Clement\n8,Unchanged\n9,Brand New\n");

    $result = app(DeviceUserImporter::class)->import($path);
    unlink($path);

    expect($result['new'])->toBe(1);
    expect($result['renamed'])->toBe(1);
    expect($result['unchanged'])->toBe(1);
    expect(AttendanceDeviceUser::where('device_user_id', '7')->sole()->device_name)->toBe('Mary Clement');
});

it('skips retired device users on import and says so', function () {
    $retired = AttendanceDeviceUser::create(['device_user_id' => '7', 'device_name' => 'Original']);
    $retired->forceFill(['retired_at' => now()])->save();

    $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
    file_put_contents($path, "device_user_id,device_name\n7,New Starter\n");

    $result = app(DeviceUserImporter::class)->import($path);
    unlink($path);

    expect($result['skipped_retired'])->toBe(1);
    expect($retired->fresh()->device_name)->toBe('Original');
});

it('rejects a file without the required columns', function () {
    $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
    file_put_contents($path, "id,name\n7,Mary\n");

    expect(fn () => app(DeviceUserImporter::class)->import($path))->toThrow(ValidationException::class);

    unlink($path);
});

it('reads a csv saved from excel with a byte-order mark', function () {
    $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
    // The BOM otherwise becomes part of the first header name, making the
    // column invisible-but-wrong.
    file_put_contents($path, chr(0xEF).chr(0xBB).chr(0xBF)."device_user_id,device_name\n7,Mary Clement\n");

    $result = app(DeviceUserImporter::class)->import($path);
    unlink($path);

    expect($result['new'])->toBe(1);
});
