<?php

use Illuminate\Support\Facades\File;

/**
 * Structural guards on the fining engine.
 *
 * These exist because the failure they prevent is silent: a second code path
 * that can write a fine, or an ingestion controller that evaluates as it
 * reads, produces charges nobody can trace back to a shift record. Grep-level
 * on purpose — they fail on the import, long before anyone wires up a call.
 */
function engineFiles(): array
{
    $paths = [
        app_path('Services/Attendance'),
        app_path('Models/Attendance'),
        app_path('Console/Commands'),
        app_path('Http/Controllers/ZKTecoController.php'),
        app_path('Filament'),
    ];

    $files = [];

    foreach ($paths as $path) {
        if (File::isFile($path)) {
            $files[$path] = File::get($path);

            continue;
        }

        if (! File::isDirectory($path)) {
            continue;
        }

        foreach (File::allFiles($path) as $file) {
            $files[$file->getPathname()] = File::get($file->getPathname());
        }
    }

    return $files;
}

it('finds the files it is meant to be guarding', function () {
    expect(engineFiles())->not->toBeEmpty();
    expect(collect(engineFiles())->keys()->filter(fn ($p) => str_contains($p, 'ShiftFinaliser')))->not->toBeEmpty();
});

it('creates shift records and fines in only the finaliser', function () {
    // Re-evaluation deliberately routes its inserts back through
    // ShiftFinaliser::persist() rather than carrying a second copy, so there
    // is exactly one place in the codebase that can charge anybody.
    $violations = [];

    foreach (engineFiles() as $path => $contents) {
        if (basename($path) === 'ShiftFinaliser.php') {
            continue;
        }

        foreach (['AttendanceShiftRecord::create(', 'AttendanceFine::create('] as $needle) {
            if (str_contains($contents, $needle)) {
                $violations[] = basename($path).' calls '.$needle;
            }
        }
    }

    expect($violations)->toBe([]);
});

it('keeps evaluation out of the ingestion controller', function () {
    $controller = File::get(app_path('Http/Controllers/ZKTecoController.php'));

    // The controller records what the device said. Judging it there would
    // mean fines created on the device's schedule rather than after the
    // shift window has actually closed.
    foreach (['AttendanceFine', 'AttendanceShiftRecord', 'ShiftEvaluator', 'ShiftFinaliser'] as $needle) {
        expect($controller)->not->toContain($needle);
    }
});

it('keeps the attendance services away from payroll, debts, stock and orders', function () {
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
    ];

    $violations = [];

    foreach (File::allFiles(app_path('Services/Attendance')) as $file) {
        $contents = File::get($file->getPathname());

        foreach ($forbidden as $needle) {
            if (str_contains($contents, $needle)) {
                $violations[] = $file->getFilename().' references '.$needle;
            }
        }
    }

    expect($violations)->toBe([]);
});

it('does not confuse the POS Shift model with a schedule', function () {
    $violations = [];

    foreach (File::allFiles(app_path('Services/Attendance')) as $file) {
        if (preg_match('/\buse App\\\\Models\\\\Shift;/', File::get($file->getPathname()))) {
            $violations[] = $file->getFilename();
        }
    }

    expect($violations)->toBe([]);
});

it('never deletes a record, fine, review item, link, assignment, template or setting', function () {
    $violations = [];

    foreach (engineFiles() as $path => $contents) {
        // Everything in this module is append-only: a deleted row is the
        // evidence behind a charge going missing.
        if (preg_match('/->(forceDelete|truncate)\(\)/', $contents, $m)) {
            $violations[] = basename($path).' calls '.$m[1].'()';
        }

        foreach (['AttendanceShiftRecord', 'AttendanceFine', 'AttendanceReviewItem', 'AttendanceDeviceLink', 'AttendanceShiftAssignment', 'AttendanceShiftTemplate', 'AttendanceSetting'] as $model) {
            if (preg_match('/'.$model.'::[^;]{0,200}->delete\(\)/s', $contents)) {
                $violations[] = basename($path).' deletes '.$model;
            }
        }
    }

    expect($violations)->toBe([]);
});

it('keeps the evaluator free of database writes', function () {
    $evaluator = File::get(app_path('Services/Attendance/ShiftEvaluator.php'));

    // Purity is what makes the back-test trustworthy and a disputed fine
    // re-derivable rather than re-litigated.
    foreach (['::create(', '->save()', '->update(', 'DB::', '->delete()', '::query()'] as $needle) {
        expect($evaluator)->not->toContain($needle);
    }
});

it('works in venue time rather than reading the app timezone', function () {
    $violations = [];

    foreach (File::allFiles(app_path('Services/Attendance')) as $file) {
        if (str_contains(File::get($file->getPathname()), "config('app.timezone')")) {
            $violations[] = $file->getFilename();
        }
    }

    expect($violations)->toBe([]);
});
