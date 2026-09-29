<?php

use App\Models\AttendanceLog;
use App\Models\BiometricEnrollment;
use App\Models\User;
use Database\Seeders\ShieldSeeder;

it('shows the machine name in the Daily Attendance table, including for unpaired badges', function () {
    $this->seed(ShieldSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    // Badge 20 has punched but is on nobody's staff profile — exactly the
    // blank-Staff-Member row this column exists for.
    AttendanceLog::create(['biometric_id' => '20', 'punch_time' => now()]);
    BiometricEnrollment::create(['biometric_id' => '20', 'name' => 'Chidi Okeke']);

    $this->actingAs($admin)
        ->get('/admin/attendance-logs')
        ->assertOk()
        ->assertSee('Name on Machine')
        ->assertSee('Chidi Okeke');
});

it('can search the Daily Attendance table by the name on the machine', function () {
    $this->seed(ShieldSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    AttendanceLog::create(['biometric_id' => '20', 'punch_time' => now()]);
    BiometricEnrollment::create(['biometric_id' => '20', 'name' => 'Chidi Okeke']);
    AttendanceLog::create(['biometric_id' => '21', 'punch_time' => now()]);
    BiometricEnrollment::create(['biometric_id' => '21', 'name' => 'Ada Nwosu']);

    $this->actingAs($admin);

    \Livewire\Livewire::test(\App\Filament\Resources\AttendanceLogs\Pages\ManageAttendanceLogs::class)
        ->searchTable('Chidi')
        ->assertCanSeeTableRecords(\App\Models\DailyAttendance::where('biometric_id', '20')->get())
        ->assertCanNotSeeTableRecords(\App\Models\DailyAttendance::where('biometric_id', '21')->get());
});
