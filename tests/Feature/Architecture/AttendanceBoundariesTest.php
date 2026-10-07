<?php

use Illuminate\Support\Facades\File;

/**
 * The attendance module computes what people were scheduled to work. It must
 * not reach into money, stock or orders — Phase 2 will add fines, and the one
 * thing that keeps that safe to build is that the schedule layer has no way
 * to write to a ledger by accident.
 *
 * These are grep-level checks on purpose: they fail on the import, long
 * before anyone wires up a call.
 */
function attendanceSourceFiles(): array
{
    $roots = [
        app_path('Models/Attendance'),
        app_path('Services/Attendance'),
    ];

    $files = [];

    foreach ($roots as $root) {
        if (! File::isDirectory($root)) {
            continue;
        }

        foreach (File::allFiles($root) as $file) {
            $files[$file->getPathname()] = File::get($file->getPathname());
        }
    }

    return $files;
}

it('finds the attendance source files it is meant to be guarding', function () {
    // Without this the whole test file passes vacuously if the namespace is
    // ever moved or renamed.
    expect(attendanceSourceFiles())->not->toBeEmpty();
});

it('never reaches into debts, payroll, stock or orders', function () {
    $forbidden = [
        'StaffDebt',
        'PayrollCompilationService',
        'PayrollPaymentService',
        'PayrollRun',
        'PayrollLine',
        'InventoryTransaction',
        'IngredientTransaction',
        'App\\Models\\Order',
        'OrderPayment',
        'OrderItem',
        'SalaryDeduction',
    ];

    $violations = [];

    foreach (attendanceSourceFiles() as $path => $contents) {
        foreach ($forbidden as $needle) {
            if (str_contains($contents, $needle)) {
                $violations[] = basename($path).' references '.$needle;
            }
        }
    }

    expect($violations)->toBe([]);
});

it('does not reference the unrelated POS Shift model', function () {
    // App\Models\Shift is a cash-handling work session, not a schedule.
    // Confusing the two would mean fining people against till handovers.
    $violations = [];

    foreach (attendanceSourceFiles() as $path => $contents) {
        if (preg_match('/\buse App\\\\Models\\\\Shift;/', $contents)) {
            $violations[] = basename($path);
        }
    }

    expect($violations)->toBe([]);
});

it('never deletes a template, assignment or settings version', function () {
    // Every one of these is append-only: the history is what makes a fine
    // arguable months later, and a delete would erase the evidence.
    $violations = [];

    foreach (attendanceSourceFiles() as $path => $contents) {
        if (preg_match('/->(delete|forceDelete|truncate)\(\)/', $contents, $m)) {
            $violations[] = basename($path).' calls '.$m[1].'()';
        }
    }

    expect($violations)->toBe([]);
});

it('works in venue time rather than reading the app timezone', function () {
    // app.timezone is UTC and stays that way; attendance is a wall-clock
    // question and must say so explicitly.
    $violations = [];

    foreach (attendanceSourceFiles() as $path => $contents) {
        if (str_contains($contents, "config('app.timezone')")) {
            $violations[] = basename($path);
        }
    }

    expect($violations)->toBe([]);
});
