<?php

use App\Models\Attendance\AttendanceDeviceUser;
use App\Models\AttendanceLog;
use App\Models\User;
use App\Models\ZktecoCommand;

it('queues one name lookup per badge that has punched', function () {
    AttendanceLog::create(['biometric_id' => '7', 'punch_time' => now()]);
    AttendanceLog::create(['biometric_id' => '7', 'punch_time' => now()->addMinute()]);
    AttendanceLog::create(['biometric_id' => '20', 'punch_time' => now()]);

    $this->artisan('hms:sync-attendance-names')->assertSuccessful();

    // Two badges, three punches — one query each, not one per punch.
    expect(ZktecoCommand::pluck('command')->sort()->values()->all())->toBe([
        'DATA QUERY USERINFO PIN=20',
        'DATA QUERY USERINFO PIN=7',
    ]);
});

it('skips badges whose name we already hold unless --all is passed', function () {
    AttendanceLog::create(['biometric_id' => '7', 'punch_time' => now()]);
    AttendanceLog::create(['biometric_id' => '20', 'punch_time' => now()]);
    AttendanceDeviceUser::create(['device_user_id' => '7', 'device_name' => 'Mary Clement']);

    $this->artisan('hms:sync-attendance-names')->assertSuccessful();
    expect(ZktecoCommand::pluck('command')->all())->toBe(['DATA QUERY USERINFO PIN=20']);

    $this->artisan('hms:sync-attendance-names --all')->assertSuccessful();
    expect(ZktecoCommand::count())->toBe(3);
});

it('also asks about badges that are paired but have never punched', function () {
    User::factory()->create(['biometric_id' => '42']);

    $this->artisan('hms:sync-attendance-names')->assertSuccessful();

    expect(ZktecoCommand::sole()->command)->toBe('DATA QUERY USERINFO PIN=42');
});

it('says so plainly when no badges are known yet', function () {
    $this->artisan('hms:sync-attendance-names')
        ->expectsOutputToContain('No machine IDs known yet')
        ->assertSuccessful();

    expect(ZktecoCommand::count())->toBe(0);
});

it('reports what the terminal has answered', function () {
    AttendanceDeviceUser::create(['device_user_id' => '7', 'device_name' => 'Mary Clement', 'last_seen_at' => now()]);
    ZktecoCommand::create(['command' => 'DATA QUERY USERINFO PIN=9', 'sent_at' => now(), 'return_code' => '-1', 'responded_at' => now()]);

    $this->artisan('hms:attendance-name-status')
        ->expectsOutputToContain('Mary Clement')
        ->expectsOutputToContain('refused')
        ->assertSuccessful();
});
