<?php

use App\Models\Attendance\AttendanceDeviceUser;
use App\Models\AttendanceLog;
use App\Models\ZktecoDevice;

/**
 * Device contact used to exist only as Log::info(), which on the production
 * server means it did not exist at all — LOG_LEVEL=warning drops those lines
 * before they reach the file. So "has the clock contacted us today?" was
 * unanswerable at exactly the moment it mattered.
 */
it('records a handshake against the serial that sent it', function () {
    $this->get('/iclock/cdata?SN=K20TEST01&options=all');

    $device = ZktecoDevice::sole();
    expect($device->serial)->toBe('K20TEST01');
    expect($device->last_handshake_at)->not->toBeNull();
    expect($device->last_poll_at)->toBeNull();
});

it('records a command poll even when nothing is queued', function () {
    // A device that polls but finds nothing still proves it is alive, and
    // the early return must not skip the stamp.
    $this->get('/iclock/getrequest?SN=K20TEST01')->assertOk();

    expect(ZktecoDevice::sole()->last_poll_at)->not->toBeNull();
});

it('records a push and counts the punches it carried', function () {
    $this->call(
        'POST',
        '/iclock/cdata?SN=K20TEST01&table=ATTLOG',
        [], [], [],
        ['CONTENT_TYPE' => 'text/plain'],
        "7\t2026-09-28 08:00:00\t0\t1\n9\t2026-09-28 08:05:00\t0\t1\n"
    )->assertOk();

    $device = ZktecoDevice::sole();
    expect($device->last_push_at)->not->toBeNull();
    expect($device->last_punch_at)->not->toBeNull();
    expect($device->punches_received)->toBe(2);
    expect(AttendanceLog::count())->toBe(2);
});

it('does not stamp a punch time for a push that carried no punches', function () {
    $this->call(
        'POST',
        '/iclock/cdata?SN=K20TEST01&table=OPERLOG',
        [], [], [],
        ['CONTENT_TYPE' => 'text/plain'],
        "OPLOG 4\t0\t2026-09-28 08:00:00\t0\t0\n"
    )->assertOk();

    $device = ZktecoDevice::sole();
    expect($device->last_push_at)->not->toBeNull();
    expect($device->last_punch_at)->toBeNull();
});

it('keeps accumulating the punch count across separate pushes', function () {
    $push = fn (string $body) => $this->call(
        'POST',
        '/iclock/cdata?SN=K20TEST01&table=ATTLOG',
        [], [], [],
        ['CONTENT_TYPE' => 'text/plain'],
        $body
    );

    $push("7\t2026-09-28 08:00:00\t0\t1\n");
    $push("9\t2026-09-28 08:05:00\t0\t1\n");

    expect(ZktecoDevice::sole()->punches_received)->toBe(2);
});

it('says plainly when the terminal has never been in touch', function () {
    $this->artisan('hms:attendance-name-status')
        ->expectsOutputToContain('has not contacted this server')
        ->assertSuccessful();
});

it('sets a machine name by hand when the device will not supply one', function () {
    $this->artisan('hms:set-machine-name', ['pairs' => ['20', 'Chidi Okeke', '31', 'Ada Nwosu']])
        ->assertSuccessful();

    expect(AttendanceDeviceUser::where('device_user_id', '20')->value('device_name'))->toBe('Chidi Okeke');
    expect(AttendanceDeviceUser::where('device_user_id', '31')->value('device_name'))->toBe('Ada Nwosu');
});

it('rejects an odd number of values rather than guessing which is which', function () {
    $this->artisan('hms:set-machine-name', ['pairs' => ['20', 'Chidi Okeke', '31']])
        ->assertFailed();

    expect(AttendanceDeviceUser::count())->toBe(0);
});

it('lets a later push from the device overwrite a hand-typed name', function () {
    $this->artisan('hms:set-machine-name', ['pairs' => ['7', 'Typo Name']]);

    $this->call(
        'POST',
        '/iclock/cdata?SN=K20TEST01&table=USERINFO',
        [], [], [],
        ['CONTENT_TYPE' => 'text/plain'],
        "USER PIN=7\tName=Mary Clement\tPri=0\n"
    );

    expect(AttendanceDeviceUser::sole()->device_name)->toBe('Mary Clement');
});
