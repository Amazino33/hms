<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$u = App\Models\User::find(1);
if ($u) {
    $u->biometric_id = '1';
    $u->save();
    App\Models\AttendanceLog::create(['user_id' => 1, 'biometric_id' => '1', 'punch_time' => now()->subHours(8), 'punch_state' => '0']);
    App\Models\AttendanceLog::create(['user_id' => 1, 'biometric_id' => '1', 'punch_time' => now()->subHours(4), 'punch_state' => '1']);
    App\Models\AttendanceLog::create(['user_id' => 1, 'biometric_id' => '1', 'punch_time' => now(), 'punch_state' => '1']);
    echo "Test data generated.\n";
}
