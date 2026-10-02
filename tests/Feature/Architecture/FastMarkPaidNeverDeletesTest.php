<?php

/**
 * Phase 0E guard: the fast Mark Paid path settles a bill by appending
 * OrderPayment rows only. Orders and their items are never deleted and
 * re-created there — that is the full payment screen's behaviour
 * (pos.blade.php processPayment()), which is deliberately out of scope
 * here and lives in the same component file. So this checks the service
 * file in full, and only the fast-path method bodies inside the component.
 */
function fastPathMethodBody(string $source, string $method): string
{
    $start = strpos($source, "function {$method}(");
    expect($start)->not->toBeFalse("pos.blade.php no longer defines {$method}()");

    $open = strpos($source, '{', $start);
    $depth = 0;

    for ($i = $open, $len = strlen($source); $i < $len; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}' && --$depth === 0) {
            return substr($source, $open, $i - $open + 1);
        }
    }

    throw new RuntimeException("Unbalanced braces in {$method}()");
}

function deletingCalls(string $code): array
{
    preg_match_all('/(->|::)\s*(delete|forceDelete|destroy|truncate)\s*\(/i', $code, $matches);

    return $matches[0];
}

it('never deletes anything in FastMarkPaidService', function () {
    $source = file_get_contents(app_path('Services/FastMarkPaidService.php'));

    expect(deletingCalls($source))->toBe([]);
});

it('never deletes anything in the fast Mark Paid methods of the POS component', function () {
    $source = file_get_contents(resource_path('views/livewire/pos.blade.php'));

    foreach (['markPaidFast', 'markPaidSplit', 'settleFastPay', 'fastPayOutstanding'] as $method) {
        expect(deletingCalls(fastPathMethodBody($source, $method)))
            ->toBe([], "{$method}() must not delete orders or order items");
    }
});
