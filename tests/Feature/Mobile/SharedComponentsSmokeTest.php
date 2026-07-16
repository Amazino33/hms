<?php

use Illuminate\Support\Facades\Blade;

/**
 * Pure Blade-compile smoke tests for the shared mobile components — catches
 * syntax errors and undefined-prop crashes before any real page wires them
 * in. Not a behavior test (there's no Livewire/JS state to assert on here).
 */
it('compiles mobile.numeric-pad without error', function () {
    $html = Blade::render('<x-mobile.numeric-pad model="amount" label="Amount" />');
    expect($html)->toContain('padDone');
});

it('compiles mobile.numeric-pad in currency+integer mode', function () {
    $html = Blade::render('<x-mobile.numeric-pad model="declaredCash" :currency="true" />');
    expect($html)->toContain('₦');
});

it('compiles mobile.stepper without error', function () {
    $html = Blade::render('<x-mobile.stepper model="qty" :min="0" :max="10" :integer="true" label="Quantity" />');
    expect($html)->toContain('longPress');
    expect($html)->toContain('padDone'); // composes mobile.numeric-pad rather than duplicating its markup
});

it('compiles mobile.chip-select as a segmented toggle for 3 options', function () {
    $html = Blade::render('<x-mobile.chip-select :options="$options" model="paymentMethod" />', [
        'options' => ['cash' => 'Cash', 'pos' => 'POS', 'transfer' => 'Transfer'],
    ]);
    expect($html)->toContain('Cash')->toContain('POS')->toContain('Transfer');
    expect(substr_count($html, 'flex-1'))->toBeGreaterThan(0); // segmented layout
});

it('compiles mobile.chip-select as a wrapping chip row for 6 options', function () {
    $html = Blade::render('<x-mobile.chip-select :options="$options" model="categoryId" />', [
        'options' => ['1' => 'A', '2' => 'B', '3' => 'C', '4' => 'D', '5' => 'E', '6' => 'F'],
    ]);
    expect($html)->toContain('flex-wrap');
});

it('compiles mobile.bottom-sheet without error', function () {
    $html = Blade::render('<x-mobile.bottom-sheet show="showDetail" title="Line Detail">Body content</x-mobile.bottom-sheet>');
    expect($html)->toContain('Body content')->toContain('Line Detail');
});

it('compiles mobile.sticky-cta-bar without error', function () {
    $html = Blade::render('<x-mobile.sticky-cta-bar retrying="hasError"><button wire:click="submit">Go</button></x-mobile.sticky-cta-bar>');
    expect($html)->toContain('Go')->toContain('safe-area-inset-bottom');
});

it('compiles mobile.pin-keypad without error', function () {
    $html = Blade::render('<x-mobile.pin-keypad title="Confirm" onComplete="$wire.declare(pin)" />');
    expect($html)->toContain('Confirm')->toContain('digit(');
});
