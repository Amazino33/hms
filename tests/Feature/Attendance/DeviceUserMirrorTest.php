<?php

use App\Models\Attendance\AttendanceDeviceUser;
use App\Models\AttendanceLog;
use App\Models\BiometricEnrollment;
use App\Models\User;
use App\Services\Attendance\DeviceUserImporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * biometric_enrollments stays exactly where it is this phase — ZKTecoController
 * and hms:set-machine-name keep writing it, untouched — and everything written
 * there is mirrored forward into attendance_device_users.
 *
 * The observer covers Eloquent writes. The reconcile command covers everything
 * else, which is the half that actually matters: an observer that silently does
 * not fire produces a badge nobody ever sees on the unmatched page.
 */
it('creates a device user when an enrolment is written through the model', function () {
    BiometricEnrollment::create(['biometric_id' => '7', 'name' => 'Mary Clement']);

    $deviceUser = AttendanceDeviceUser::where('device_user_id', '7')->sole();
    expect($deviceUser->device_name)->toBe('Mary Clement');
});

it('updates the device name when the enrolment is renamed', function () {
    BiometricEnrollment::create(['biometric_id' => '7', 'name' => 'Mary']);
    BiometricEnrollment::where('biometric_id', '7')->first()->update(['name' => 'Mary Clement']);

    expect(AttendanceDeviceUser::where('device_user_id', '7')->sole()->device_name)->toBe('Mary Clement');
});

it('mirrors a repeated identical push without error', function () {
    // updateOrCreate on unchanged values fires saved but not updated, which
    // is why the observer listens to saved.
    BiometricEnrollment::updateOrCreate(['biometric_id' => '7'], ['name' => 'Mary']);
    BiometricEnrollment::updateOrCreate(['biometric_id' => '7'], ['name' => 'Mary']);

    expect(AttendanceDeviceUser::where('device_user_id', '7')->count())->toBe(1);
});

it('never modifies a retired device user', function () {
    $retired = AttendanceDeviceUser::create([
        'device_user_id' => '7',
        'device_name' => 'Original Holder',
    ]);
    $retired->forceFill(['retired_at' => now()])->save();

    // The terminal happily keeps pushing records for an ID it has reassigned.
    BiometricEnrollment::create(['biometric_id' => '7', 'name' => 'New Starter']);

    expect($retired->fresh()->device_name)->toBe('Original Holder');
});

it('does not wipe a held name when the device pushes a blank one', function () {
    BiometricEnrollment::create(['biometric_id' => '7', 'name' => 'Mary Clement']);
    BiometricEnrollment::where('biometric_id', '7')->first()->update(['name' => null]);

    expect(AttendanceDeviceUser::where('device_user_id', '7')->sole()->device_name)->toBe('Mary Clement');
});

it('picks up a row written straight through the query builder', function () {
    // The exact case the observer cannot see, and the reason the command
    // exists at all.
    DB::table('biometric_enrollments')->insert([
        'biometric_id' => '42',
        'name' => 'Bypassed The Model',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(AttendanceDeviceUser::where('device_user_id', '42')->exists())->toBeFalse();

    $this->artisan('attendance:reconcile-device-users')->assertSuccessful();

    expect(AttendanceDeviceUser::where('device_user_id', '42')->sole()->device_name)->toBe('Bypassed The Model');
});

it('picks up a badge that has punched but was never enrolled', function () {
    AttendanceLog::create(['biometric_id' => '55', 'punch_time' => now()]);

    $this->artisan('attendance:reconcile-device-users')->assertSuccessful();

    $deviceUser = AttendanceDeviceUser::where('device_user_id', '55')->sole();
    expect($deviceUser->device_name)->toBeNull();
    expect($deviceUser->first_seen_at)->not->toBeNull();
});

it('changes nothing when run a second time', function () {
    DB::table('biometric_enrollments')->insert([
        'biometric_id' => '42', 'name' => 'Someone', 'created_at' => now(), 'updated_at' => now(),
    ]);
    AttendanceLog::create(['biometric_id' => '55', 'punch_time' => now()]);

    $this->artisan('attendance:reconcile-device-users')->assertSuccessful();

    $snapshot = AttendanceDeviceUser::orderBy('device_user_id')
        ->get(['device_user_id', 'device_name', 'first_seen_at', 'updated_at'])
        ->toArray();

    $this->artisan('attendance:reconcile-device-users')->assertSuccessful();

    expect(AttendanceDeviceUser::orderBy('device_user_id')
        ->get(['device_user_id', 'device_name', 'first_seen_at', 'updated_at'])
        ->toArray())->toBe($snapshot);
});

it('stamps first seen from the earliest punch and never moves it afterwards', function () {
    AttendanceLog::create(['biometric_id' => '55', 'punch_time' => CarbonImmutable::parse('2026-03-10 07:00:00', 'UTC')]);
    $this->artisan('attendance:reconcile-device-users');

    $first = AttendanceDeviceUser::where('device_user_id', '55')->sole()->first_seen_at;

    // A punch arriving late from the device's offline buffer must not rewrite
    // a date other records already cite.
    AttendanceLog::create(['biometric_id' => '55', 'punch_time' => CarbonImmutable::parse('2026-02-01 07:00:00', 'UTC')]);
    $this->artisan('attendance:reconcile-device-users');

    expect(AttendanceDeviceUser::where('device_user_id', '55')->sole()->first_seen_at->eq($first))->toBeTrue();
});

it('never creates a link while mirroring', function () {
    User::factory()->create(['biometric_id' => '7']);
    BiometricEnrollment::create(['biometric_id' => '7', 'name' => 'Mary Clement']);

    $this->artisan('attendance:reconcile-device-users');

    // Pairing is a human decision; the mirror only ever carries names.
    expect(AttendanceDeviceUser::where('device_user_id', '7')->sole()->activeLink())->toBeNull();
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
