<?php

use App\Models\AttendanceLog;
use App\Models\BiometricEnrollment;
use App\Models\User;
use App\Models\ZktecoCommand;

/**
 * The name typed into the terminal at enrolment is the only name a badge has
 * until somebody pairs it to a staff profile — and the Daily Attendance table
 * was showing those rows with a blank Staff Member and nothing else to go on.
 *
 * ATTLOG carries no names, so the name has to come from a USERINFO push, and
 * the device only sends those unprompted when a user is enrolled or edited.
 * Hence the command queue: we ask, it answers on its next poll.
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

    $enrollment = BiometricEnrollment::sole();
    expect($enrollment->biometric_id)->toBe('7');
    expect($enrollment->name)->toBe('Mary Clement');
});

it('keeps a multi-word name intact instead of splitting it at the first space', function () {
    $this->call(
        'POST',
        '/iclock/cdata?SN=K20TEST01&table=USERINFO',
        [], [], [],
        ['CONTENT_TYPE' => 'text/plain'],
        "USER PIN=5\tName=Ndifreke Usungurua Offot\tPri=0\tCard=0\n"
    );

    expect(BiometricEnrollment::sole()->name)->toBe('Ndifreke Usungurua Offot');
});

it('reads USERINFO records that arrive inside an OPERLOG push', function () {
    // Some firmware answers a user query on the OPERLOG table rather than
    // USERINFO, so the line prefix decides, not the table parameter.
    $this->call(
        'POST',
        '/iclock/cdata?SN=K20TEST01&table=OPERLOG',
        [], [], [],
        ['CONTENT_TYPE' => 'text/plain'],
        "OPLOG 4\t0\t2026-09-28 08:00:00\t0\t0\nUSER PIN=32\tName=Jessica Gaius\tPri=0\n"
    );

    expect(BiometricEnrollment::sole()->name)->toBe('Jessica Gaius');
});

it('does not book a punch or a fine from a USERINFO push', function () {
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
    BiometricEnrollment::create(['biometric_id' => '7', 'name' => 'Mary Clement']);

    $this->call(
        'POST',
        '/iclock/cdata?SN=K20TEST01&table=USERINFO',
        [], [], [],
        ['CONTENT_TYPE' => 'text/plain'],
        "USER PIN=7\tName=\tPri=0\n"
    );

    $enrollment = BiometricEnrollment::sole();
    expect($enrollment->name)->toBe('Mary Clement');
    expect($enrollment->last_seen_at)->not->toBeNull();
});

it('hands queued commands to the device on its next poll and marks them sent', function () {
    ZktecoCommand::create(['command' => 'DATA QUERY USERINFO PIN=7']);

    $response = $this->get('/iclock/getrequest?SN=K20TEST01&INFO=1');

    $response->assertOk();
    $response->assertSee('DATA QUERY USERINFO PIN=7');
    // The id prefix is how the device reports back which command it ran.
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
