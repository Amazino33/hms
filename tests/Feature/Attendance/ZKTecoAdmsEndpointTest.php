<?php

use App\Models\AttendanceLog;
use App\Models\SalaryDeduction;
use App\Models\User;

/**
 * The K20 Pro terminal judges the whole integration on four plain-text
 * endpoints. It shows a red X — and pushes nothing, ever — if the first one
 * does not answer correctly, which is exactly how this shipped: only
 * POST /iclock/cdata was registered, so the device's opening GET to that same
 * URI drew a 405 from the router and the conversation ended there.
 */
it('answers the handshake GET with an options block instead of 405', function () {
    $response = $this->get('/iclock/cdata?SN=K20TEST01&options=all&pushver=2.4.1&language=69');

    $response->assertOk();
    $response->assertSee('GET OPTION FROM: K20TEST01');
    // TimeZone=1 is what keeps the device's own stamps in Lagos wall-clock.
    $response->assertSee('TimeZone=1');
    $response->assertSee('Realtime=1');
});

it('answers the command poll and the command acknowledgement with OK', function () {
    $this->get('/iclock/getrequest?SN=K20TEST01&INFO=1')->assertOk()->assertSee('OK');
    $this->post('/iclock/devicecmd?SN=K20TEST01', [])->assertOk()->assertSee('OK');
});

it('stores a punch in UTC from the terminal\'s Lagos wall-clock time', function () {
    $user = User::factory()->create(['biometric_id' => '7', 'shift_start_time' => '08:00:00']);

    $response = $this->call(
        'POST',
        '/iclock/cdata?SN=K20TEST01&table=ATTLOG&Stamp=9999',
        [], [], [],
        ['CONTENT_TYPE' => 'text/plain'],
        "7\t2026-09-11 07:45:00\t0\t1\t0\t0\n"
    );

    $response->assertOk();
    // The count is the firmware's confirmation that the batch landed.
    $response->assertSee('OK: 1');

    $log = AttendanceLog::sole();
    expect($log->user_id)->toBe($user->id);
    // 07:45 Lagos is 06:45 UTC — storage stays UTC per App\Support\VenueTime.
    expect($log->punch_time->utc()->format('Y-m-d H:i:s'))->toBe('2026-09-11 06:45:00');
    // On time, so no penalty.
    expect(SalaryDeduction::count())->toBe(0);
});

it('fines a late arrival once, however many times the batch is re-sent', function () {
    $user = User::factory()->create(['biometric_id' => '7', 'shift_start_time' => '08:00:00']);

    $push = fn () => $this->call(
        'POST',
        '/iclock/cdata?SN=K20TEST01&table=ATTLOG&Stamp=9999',
        [], [], [],
        ['CONTENT_TYPE' => 'text/plain'],
        "7\t2026-09-11 08:20:00\t0\t1\t0\t0\n"
    );

    $push()->assertOk();
    // The device repeats a batch it does not see acknowledged.
    $push()->assertOk();

    expect(AttendanceLog::count())->toBe(1);
    expect(SalaryDeduction::count())->toBe(1);

    $deduction = SalaryDeduction::sole();
    expect($deduction->user_id)->toBe($user->id);
    expect((float) $deduction->amount)->toBe(500.0);
    // Both sides of the comparison read as Lagos wall-clock, not UTC.
    expect($deduction->reason)->toContain('Expected: 08:00')->toContain('Arrived: 08:20');
});

/**
 * OPERLOG, USERINFO and the fingerprint tables arrive on the very same URL.
 * Parsed as punches they would book phantom attendance and — since an admin
 * opening a device menu is rarely doing so before 8am — phantom 500 Naira
 * fines against whoever happened to match the first field.
 */
it('acknowledges but discards non-attendance tables pushed to the same URL', function () {
    User::factory()->create(['biometric_id' => '7', 'shift_start_time' => '08:00:00']);

    $response = $this->call(
        'POST',
        '/iclock/cdata?SN=K20TEST01&table=OPERLOG&Stamp=9999',
        [], [], [],
        ['CONTENT_TYPE' => 'text/plain'],
        "OPLOG 7\t2026-09-11 09:30:00\t0\t0\t0\n"
    );

    $response->assertOk()->assertSee('OK');
    expect(AttendanceLog::count())->toBe(0);
    expect(SalaryDeduction::count())->toBe(0);
});

it('logs a punch from an unknown badge without inventing a user or a fine', function () {
    $this->call(
        'POST',
        '/iclock/cdata?SN=K20TEST01&table=ATTLOG&Stamp=9999',
        [], [], [],
        ['CONTENT_TYPE' => 'text/plain'],
        "999\t2026-09-11 10:00:00\t0\t1\t0\t0\n"
    )->assertOk();

    $log = AttendanceLog::sole();
    expect($log->user_id)->toBeNull();
    expect($log->biometric_id)->toBe('999');
    expect(SalaryDeduction::count())->toBe(0);
});

/**
 * Not every firmware revision emits tabs; some pad the same fields with
 * spaces, which splits the datetime in two and needs recombining.
 */
it('parses a space-padded ATTLOG line as well as a tab-separated one', function () {
    User::factory()->create(['biometric_id' => '7', 'shift_start_time' => '08:00:00']);

    $this->call(
        'POST',
        '/iclock/cdata?SN=K20TEST01&table=ATTLOG&Stamp=9999',
        [], [], [],
        ['CONTENT_TYPE' => 'text/plain'],
        "7 2026-09-11 08:30:00 0 1\n"
    )->assertSee('OK: 1');

    expect(AttendanceLog::sole()->punch_time->utc()->format('H:i'))->toBe('07:30');
    expect(SalaryDeduction::count())->toBe(1);
});
