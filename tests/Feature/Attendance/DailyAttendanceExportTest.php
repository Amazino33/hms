<?php

use App\Filament\Resources\AttendanceLogs\AttendanceLogResource;
use App\Filament\Resources\AttendanceLogs\Pages\ManageAttendanceLogs;
use App\Models\AttendanceLog;
use App\Models\BiometricEnrollment;
use App\Models\DailyAttendance;
use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Livewire\Livewire;

/**
 * The table paginates at 50 but the point of an export is the whole set, so
 * these assert on what the file contains rather than on what is on screen.
 */
beforeEach(function () {
    $this->seed(ShieldSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');
    $this->actingAs($this->admin);
});

/**
 * Reads the streamed CSV back out of the response the action returns.
 */
function csvBody(\Symfony\Component\HttpFoundation\StreamedResponse $response): string
{
    ob_start();
    $response->sendContent();

    return ob_get_clean();
}

it('exports every matching row, not just the visible page', function () {
    // 60 rows across 60 days, comfortably past the 50-row page size.
    for ($i = 0; $i < 60; $i++) {
        AttendanceLog::create([
            'biometric_id' => '7',
            'punch_time' => now()->subDays($i)->setTime(8, 0),
        ]);
    }

    $component = Livewire::test(ManageAttendanceLogs::class);
    $response = AttendanceLogResource::streamAttendanceCsv($component->instance());

    $lines = array_filter(explode("\n", trim(csvBody($response))));

    // 60 data rows plus the header.
    expect($lines)->toHaveCount(61);
});

it('includes the machine name and the staff name side by side', function () {
    $user = User::factory()->create([
        'name' => 'Jessica Gaius',
        'biometric_id' => '32',
        'shift_start_time' => '08:00:00',
    ]);
    BiometricEnrollment::create(['biometric_id' => '32', 'name' => 'Jessica']);
    AttendanceLog::create([
        'user_id' => $user->id,
        'biometric_id' => '32',
        // 07:43 Lagos is 06:43 UTC — on time against an 08:00 start.
        'punch_time' => \Carbon\Carbon::parse('2026-09-27 06:43:00'),
    ]);

    $component = Livewire::test(ManageAttendanceLogs::class);
    $body = csvBody(AttendanceLogResource::streamAttendanceCsv($component->instance()));

    expect($body)->toContain('Jessica Gaius');
    expect($body)->toContain('Jessica');
    expect($body)->toContain('2026-09-27');
    expect($body)->toContain('On Time');
    // Times exported in venue time, 24-hour, so a sheet can sort them.
    expect($body)->toContain('07:43');
});

it('exports a name for a badge with no staff profile at all', function () {
    BiometricEnrollment::create(['biometric_id' => '25', 'name' => 'Annie']);
    AttendanceLog::create(['biometric_id' => '25', 'punch_time' => now()]);

    $component = Livewire::test(ManageAttendanceLogs::class);
    $body = csvBody(AttendanceLogResource::streamAttendanceCsv($component->instance()));

    expect($body)->toContain('Annie');
    expect($body)->toContain('No Shift Time');
});

it('carries the lateness in minutes so the sheet can total it', function () {
    $user = User::factory()->create(['biometric_id' => '13', 'shift_start_time' => '08:00:00']);
    AttendanceLog::create([
        'user_id' => $user->id,
        'biometric_id' => '13',
        // 10:40 Lagos = 09:40 UTC, 160 minutes past an 08:00 start.
        'punch_time' => \Carbon\Carbon::parse('2026-09-25 09:40:00'),
    ]);

    $component = Livewire::test(ManageAttendanceLogs::class);
    $body = csvBody(AttendanceLogResource::streamAttendanceCsv($component->instance()));

    expect($body)->toContain('Late');
    expect($body)->toContain('08:00');
    expect($body)->toContain('160');
});

it('honours the date filter rather than dumping the whole table', function () {
    AttendanceLog::create(['biometric_id' => '1', 'punch_time' => \Carbon\Carbon::parse('2026-09-01 08:00:00')]);
    AttendanceLog::create(['biometric_id' => '2', 'punch_time' => \Carbon\Carbon::parse('2026-09-20 08:00:00')]);

    $component = Livewire::test(ManageAttendanceLogs::class)
        ->filterTable('date', ['from' => '2026-09-15', 'until' => '2026-09-30']);

    $body = csvBody(AttendanceLogResource::streamAttendanceCsv($component->instance()));

    expect($body)->toContain('2026-09-20');
    expect($body)->not->toContain('2026-09-01');
});

it('starts with a UTF-8 BOM so Excel does not mangle a non-ASCII name', function () {
    BiometricEnrollment::create(['biometric_id' => '5', 'name' => 'Ndifreke Usungurua Offot']);
    AttendanceLog::create(['biometric_id' => '5', 'punch_time' => now()]);

    $component = Livewire::test(ManageAttendanceLogs::class);
    $body = csvBody(AttendanceLogResource::streamAttendanceCsv($component->instance()));

    expect(substr($body, 0, 3))->toBe(chr(0xEF).chr(0xBB).chr(0xBF));
});

it('counts early arrival as zero minutes late, never negative', function () {
    $user = User::factory()->create(['biometric_id' => '9', 'shift_start_time' => '08:00:00']);
    AttendanceLog::create([
        'user_id' => $user->id,
        'biometric_id' => '9',
        // 07:30 Lagos — half an hour early.
        'punch_time' => \Carbon\Carbon::parse('2026-09-25 06:30:00'),
    ]);

    expect(DailyAttendance::sole()->minutesLate())->toBe(0);
    expect(DailyAttendance::sole()->status())->toBe('On Time');
});

it('offers the download button on the page itself', function () {
    AttendanceLog::create(['biometric_id' => '7', 'punch_time' => now()]);

    Livewire::test(ManageAttendanceLogs::class)
        ->assertTableActionExists('export');

    $this->get('/admin/attendance-logs')
        ->assertOk()
        ->assertSee('Download CSV');
});
