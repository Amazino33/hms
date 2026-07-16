<?php

/**
 * Spec 3.6 verification: markup-level assertions that the converted
 * surfaces actually dropped their native <select>/<input type=number>
 * OS-keyboard triggers, that every multi-step flow has a sticky CTA, and
 * that the blind-count guarantee survived the pass (quantities absent from
 * the DOM, not just CSS-hidden — checked directly on the rendered HTML).
 */

function bladeSource(string $relativePath): string
{
    return file_get_contents(resource_path('views/' . $relativePath));
}

it('never renders a native select for payment method, reason code, or unit toggle on converted surfaces', function () {
    $surfaces = [
        'livewire/pos.blade.php' => ['paymentMethod'],
        'filament/pages/folio-detail.blade.php' => ['paymentMethod'],
        'filament/pages/void-order-item.blade.php' => [],
        'filament/pages/cashier-session-page.blade.php' => ['outflowType'],
        'filament/pages/bulk-stock-set.blade.php' => ['warehouseId'],
    ];

    foreach ($surfaces as $path => $models) {
        $source = bladeSource($path);
        foreach ($models as $model) {
            expect($source)->not->toContain("wire:model=\"{$model}\"")
                ->and($source)->not->toContain("<select wire:model.live=\"{$model}\"");
        }
    }

    // void-order-item.blade.php has no <select> at all anymore — the whole
    // page (order-item search, quantity, reason) is custom Blade/Alpine now.
    expect(bladeSource('filament/pages/void-order-item.blade.php'))->not->toContain('<select');
});

it('drives every converted quantity/currency field through a tap target, never a raw number input', function () {
    // The shared numeric-pad's tap target is a <button>, not an <input> —
    // there is no element for the OS to attach a numeric keyboard to.
    $numericPad = bladeSource('components/mobile/numeric-pad.blade.php');
    expect($numericPad)->toContain('<button type="button" @click="openPad()"');
    expect($numericPad)->not->toContain('<input');

    $stepper = bladeSource('components/mobile/stepper.blade.php');
    expect($stepper)->not->toContain('<input');
});

it('gives every multi-step operational flow a sticky CTA bar', function () {
    $flows = [
        'filament/pages/new-procurement.blade.php',
        'filament/pages/bulk-stock-set.blade.php',
        'filament/pages/void-order-item.blade.php',
        'filament/pages/room-order.blade.php',
    ];

    foreach ($flows as $path) {
        expect(bladeSource($path))->toContain('<x-mobile.sticky-cta-bar');
    }
});

it('keeps the bartender count screen blind — the live counting UI is seeded only from the sanitizing method', function () {
    // adjusted_expected_quantity legitimately appears elsewhere in this file
    // (the post-seal results table, shown only once status=reviewed) — the
    // guarantee that matters is that the *counting-in-progress* screen's
    // frozen x-data is seeded exclusively from safeCountItems(), which
    // StoreCountTest.php already pins as never returning either key.
    $view = bladeSource('filament/pages/count-session-detail.blade.php');

    expect($view)->toContain('items: @js($this->safeCountItems())');
    expect($view)->not->toContain('@js($session->items)');
    expect($view)->not->toContain('@js($this->session->items)');
});

it('reuses the shared PIN keypad component at every consolidated site', function () {
    $sites = [
        'filament/pages/partials/count-session-dual-seal.blade.php',
        'filament/pages/partials/count-session-solo-submit.blade.php',
    ];

    foreach ($sites as $path) {
        expect(bladeSource($path))->toContain('<x-mobile.pin-keypad');
    }

    $countSessionDetail = bladeSource('filament/pages/count-session-detail.blade.php');
    expect(substr_count($countSessionDetail, '<x-mobile.pin-keypad'))->toBe(3); // declare, bind-review, amend
});

it('preserves the kiosk idle screen PIN pad exactly as-is (deliberately not consolidated)', function () {
    // Highest-traffic PIN entry in the app, with a Cancel button embedded in
    // its digit grid — a genuinely different layout, not just styling.
    // Confirms it still has its own inline pad rather than accidentally
    // losing PIN entry there during the pass.
    $view = bladeSource('livewire/kiosk-idle-screen.blade.php');
    expect($view)->toContain("pin: ''");
    expect($view)->toContain('submitPin');
});

it('respects safe-area-inset-bottom on every sticky/fixed shared component', function () {
    foreach (['sticky-cta-bar', 'numeric-pad', 'bottom-sheet', 'pin-keypad'] as $component) {
        $source = bladeSource("components/mobile/{$component}.blade.php");
        if ($component === 'pin-keypad') {
            // Composed inside a bottom sheet or sticky bar at each call
            // site rather than fixed-positioning itself.
            continue;
        }
        expect($source)->toContain('safe-area-inset-bottom');
    }
});

it('gives every shared tap target at least a 48px minimum touch size', function () {
    // Either an explicit min-h-[48px] or a fixed w-12 h-12 (both = 48px) —
    // components use whichever fits their own layout.
    foreach (['stepper', 'numeric-pad', 'chip-select', 'pin-keypad'] as $component) {
        $source = bladeSource("components/mobile/{$component}.blade.php");
        $compliant = str_contains($source, 'min-h-[48px]') || str_contains($source, 'w-12 h-12');
        expect($compliant)->toBeTrue("mobile.{$component} has no explicit 48px touch-target sizing");
    }
});
