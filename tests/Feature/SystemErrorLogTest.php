<?php

use App\Filament\Pages\SystemErrorLog;
use App\Models\PagePermission;
use App\Models\User;
use App\Services\ErrorLogRecorder;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * ErrorLogRecorder writes to storage/logs/app-errors.log, not the database
 * — this test suite runs against that real file, so every test clears it
 * first/last rather than relying on RefreshDatabase to isolate it.
 */
beforeEach(function () {
    ErrorLogRecorder::clear();
});

afterEach(function () {
    ErrorLogRecorder::clear();
});

it('records an exception with its class, message, and location', function () {
    ErrorLogRecorder::record(new \RuntimeException('Something broke', 42));

    $entries = ErrorLogRecorder::recent();

    expect($entries)->toHaveCount(1);
    expect($entries[0]['class'])->toBe('RuntimeException');
    expect($entries[0]['message'])->toBe('Something broke');
    expect($entries[0]['code'])->toBe(42);
    expect($entries[0]['file'])->toBe(__FILE__);
});

it('returns entries newest first', function () {
    ErrorLogRecorder::record(new \Exception('first'));
    ErrorLogRecorder::record(new \Exception('second'));

    $entries = ErrorLogRecorder::recent();

    expect($entries)->toHaveCount(2);
    expect($entries[0]['message'])->toBe('second');
    expect($entries[1]['message'])->toBe('first');
});

it('survives a broken exception being reported without throwing itself', function () {
    // Simulates the real production incident this feature exists for: a
    // database-connection failure. Recording it must not itself touch the
    // database in a way that could throw again.
    ErrorLogRecorder::record(new \PDOException('SQLSTATE[HY000] [2002] Connection refused'));

    $entries = ErrorLogRecorder::recent();

    expect($entries)->toHaveCount(1);
    expect($entries[0]['class'])->toBe('PDOException');
});

it('clears the log', function () {
    ErrorLogRecorder::record(new \Exception('to be cleared'));
    expect(ErrorLogRecorder::recent())->toHaveCount(1);

    ErrorLogRecorder::clear();

    expect(ErrorLogRecorder::recent())->toHaveCount(0);
});

it('automatically records an uncaught exception reported through the framework', function () {
    report(new \Exception('reported via the real exception handler'));

    $entries = ErrorLogRecorder::recent();

    expect($entries)->toHaveCount(1);
    expect($entries[0]['message'])->toBe('reported via the real exception handler');
});

it('grants a super_admin and an admin access to the error log page, and blocks a plain waiter', function () {
    Artisan::call('db:seed', ['--class' => 'PagePermissionsSeeder', '--force' => true]);

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));

    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'admin']));

    $waiter = User::factory()->create();
    $waiter->assignRole(Role::firstOrCreate(['name' => 'waiter']));

    test()->actingAs($superAdmin);
    expect(\App\Services\PermissionService::canAccessPage(SystemErrorLog::class))->toBeTrue();

    test()->actingAs($admin);
    expect(\App\Services\PermissionService::canAccessPage(SystemErrorLog::class))->toBeTrue();

    test()->actingAs($waiter);
    expect(\App\Services\PermissionService::canAccessPage(SystemErrorLog::class))->toBeFalse();
});

it('renders the error log page and can clear the log through it', function () {
    ErrorLogRecorder::record(new \Exception('visible on the page'));

    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'admin']));
    PagePermission::firstOrCreate(
        ['page_class' => SystemErrorLog::class, 'role_name' => 'admin'],
        ['page_class' => SystemErrorLog::class, 'page_name' => 'Error Log', 'role_name' => 'admin']
    );

    Livewire::actingAs($admin)
        ->test(SystemErrorLog::class)
        ->assertSee('visible on the page')
        ->call('clearLog');

    expect(ErrorLogRecorder::recent())->toHaveCount(0);
});
