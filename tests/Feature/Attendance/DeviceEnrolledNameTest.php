<?php

use App\Models\Attendance\AttendanceDeviceUser;
use App\Models\AttendanceLog;
use App\Models\User;
use App\Models\ZktecoCommand;

/**
 * The name typed into the terminal at enrolment is the only name a badge has
 * until somebody pairs it to a staff profile.
 *
 * Since Phase 2 the controller writes attendance_device_users directly:
 * biometric_enrollments and its mirror observer are gone, so there is one
 * table and no bridge to fall out of step.
 */
it('stores the enrolled name from a USERINFO push', function () {
    $response = $this->call(
        'POST',
        '/iclock/cdata?SN=K20TEST01&table=USERINFO&Stamp=9999',
        [], [], [],
        ['CONTENT_TYPE' => 'text/plain'],
        "USER PIN=7\tName=Mary Clement\tPri=0\tPasswd=\tCard=0\tGrp=1\tTZ=0000000000000000\n"
    );

    $response->assertOk();

    $deviceUser = AttendanceDeviceUser::sole();
    expect($deviceUser->device_user_id)->toBe('7');
    expect($deviceUser->device_name)->toBe('Mary Clement');
});

it('keeps a multi-word name intact instead of splitting it at the first space', function () {
    $this->call(
        'POST',
        '/iclock/cdata?SN=K20TEST01&table=USERINFO',
        [], [], [],
        ['CONTENT_TYPE' => 'text/plain'],
        "USER PIN=5\tName=Ndifreke Usungurua Offot\tPri=0\tCard=0\n"
    );

    expect(AttendanceDeviceUser::sole()->device_name)->toBe('Ndifreke Usungurua Offot');
});

it('reads USERINFO records that arrive inside an OPERLOG push', function () {
    // Some firmware answers a user query on OPERLOG rather than USERINFO, so
    // the line prefix decides, not the table parameter.
    $this->call(
        'POST',
        '/iclock/cdata?SN=K20TEST01&table=OPERLOG',
        [], [], [],
        ['CONTENT_TYPE' => 'text/plain'],
        "OPLOG 4\t0\t2026-09-28 08:00:00\t0\t0\nUSER PIN=32\tName=Jessica Gaius\tPri=0\n"
    );

    expect(AttendanceDeviceUser::sole()->device_name)->toBe('Jessica Gaius');
});

it('does not book a punch from a USERINFO push', function () {
    User::factory()->create(['biometric_id' => '7', 'shift_start_time' => '08:00:00']);

    $this->call(
        'POST',
        '/iclock/cdata?SN=K20TEST01&table=USERINFO',
        [], [], [],
        ['CONTENT_TYPE' => 'text/plain'],
        "USER PIN=7\tName=Mary Clement\tPri=0\n"
    );

    expect(AttendanceLog::count())->toBe(0);
});

it('keeps the name it already has when the device re-sends the record unnamed', function () {
    AttendanceDeviceUser::create(['device_user_id' => '7', 'device_name' => 'Mary Clement']);

    $this->call(
        'POST',
        '/iclock/cdata?SN=K20TEST01&table=USERINFO',
        [], [], [],
        ['CONTENT_TYPE' => 'text/plain'],
        "USER PIN=7\tName=\tPri=0\n"
    );

    // A blank push means "enrolled, never named" — not "forget the name".
    expect(AttendanceDeviceUser::sole()->device_name)->toBe('Mary Clement');
});

it('never overwrites a retired badge from a device push', function () {
    $retired = AttendanceDeviceUser::create(['device_user_id' => '7', 'device_name' => 'Original Holder']);
    $retired->forceFill(['retired_at' => now()])->save();

    // The terminal keeps pushing records for IDs it has since reassigned.
    $this->call(
        'POST',
        '/iclock/cdata?SN=K20TEST01&table=USERINFO',
        [], [], [],
        ['CONTENT_TYPE' => 'text/plain'],
        "USER PIN=7\tName=New Starter\tPri=0\n"
    );

    expect($retired->fresh()->device_name)->toBe('Original Holder');
});

it('creates the badge and stamps last seen from an ordinary punch', function () {
    $this->call(
        'POST',
        '/iclock/cdata?SN=K20TEST01&table=ATTLOG&Stamp=9999',
        [], [], [],
        ['CONTENT_TYPE' => 'text/plain'],
        "44\t2026-10-05 08:00:00\t0\t1\t0\t0\n"
    )->assertOk();

    $deviceUser = AttendanceDeviceUser::where('device_user_id', '44')->sole();
    expect($deviceUser->device_name)->toBeNull();
    expect($deviceUser->last_seen_at)->not->toBeNull();
});

it('never rewinds last seen when the device dumps an old backlog', function () {
    $push = fn (string $body) => $this->call(
        'POST',
        '/iclock/cdata?SN=K20TEST01&table=ATTLOG',
        [], [], [],
        ['CONTENT_TYPE' => 'text/plain'],
        $body
    );

    $push("44\t2026-10-05 08:00:00\t0\t1\n");
    $newest = AttendanceDeviceUser::sole()->last_seen_at;

    // An offline buffer delivers older punches after newer ones; letting
    // those move the stamp back would make a badge in daily use look dormant.
    $push("44\t2026-09-01 08:00:00\t0\t1\n");

    expect(AttendanceDeviceUser::sole()->last_seen_at->eq($newest))->toBeTrue();
});

it('hands queued commands to the device on its next poll and marks them sent', function () {
    ZktecoCommand::create(['command' => 'DATA QUERY USERINFO PIN=7']);

    $response = $this->get('/iclock/getrequest?SN=K20TEST01&INFO=1');

    $response->assertOk();
    $response->assertSee('DATA QUERY USERINFO PIN=7');
    $response->assertSee('C:'.ZktecoCommand::sole()->id.':');

    expect(ZktecoCommand::sole()->sent_at)->not->toBeNull();
});

it('does not hand the same command out twice', function () {
    ZktecoCommand::create(['command' => 'DATA QUERY USERINFO PIN=7']);

    $this->get('/iclock/getrequest?SN=K20TEST01');
    $second = $this->get('/iclock/getrequest?SN=K20TEST01');

    $second->assertOk();
    $second->assertSee('OK');
    $second->assertDontSee('DATA QUERY');
});

it('records the return code the device reports for a command', function () {
    $command = ZktecoCommand::create(['command' => 'DATA QUERY USERINFO PIN=7', 'sent_at' => now()]);

    $this->call(
        'POST',
        '/iclock/devicecmd?SN=K20TEST01',
        [], [], [],
        ['CONTENT_TYPE' => 'text/plain'],
        "ID={$command->id}&Return=0&CMD=DATA"
    )->assertOk();

    $command->refresh();
    expect($command->return_code)->toBe('0');
    expect($command->responded_at)->not->toBeNull();
});

it('still answers OK when nothing is queued', function () {
    $this->get('/iclock/getrequest?SN=K20TEST01')->assertOk()->assertSee('OK');
});
