<?php

use Illuminate\Support\Facades\Blade;

/**
 * Real-device testing (Android, settlement screen) found the numeric pad
 * effectively unusable: tapping digits closed the pad, it rendered as a
 * half-width panel instead of a full-width bottom sheet, and the header
 * showed the wrong field's label. Root cause turned out to be twofold:
 * (1) the compiled CSS bundle (public/build/assets) was last rebuilt
 * BEFORE the entire mobile UI/UX pass was written — every Tailwind class
 * these components use (fixed positioning, z-index, the scrim, the sheet
 * transform) was silently absent from what production actually served,
 * since this host can't run npm and assets are committed pre-built; and
 * (2) two numeric-pad instances on one screen (settlement's cash + POS
 * fields) teleport structurally-identical, unkeyed DOM to <body>, which a
 * Livewire re-render can conflate. These tests pin the code-level fixes;
 * the CSS rebuild itself isn't something Pest can assert on, so the
 * critical-class checks below are the regression guard for "someone added
 * a new class here and forgot to rebuild" going forward.
 */
it('gives two numeric-pad instances on the same screen distinct wire:key identities', function () {
    $html = Blade::render(<<<'BLADE'
        <x-mobile.numeric-pad model="cashierCountedCash" :currency="true" label="Amount counted" />
        <x-mobile.numeric-pad model="posMachineAmount" :currency="true" label="Machine batch total" />
    BLADE);

    preg_match_all('/wire:key="(pad-[a-zA-Z0-9]+-(?:scrim|sheet))"/', $html, $matches);

    expect($matches[1])->toHaveCount(4); // 2 instances x (scrim + sheet)
    expect(array_unique($matches[1]))->toHaveCount(4); // all four distinct
});

it('renders the pad as a teleported full-width bottom sheet with a scrim, not an inline half-width panel', function () {
    $html = Blade::render('<x-mobile.numeric-pad model="amount" :currency="true" label="Amount" />');

    expect($html)->toContain('x-teleport="body"');
    expect($html)->toContain('fixed inset-x-0 bottom-0');
    expect($html)->toContain('bg-black/40'); // scrim
    expect($html)->toContain('z-[70]')->toContain('z-[71]'); // sheet above scrim
});

it('dismisses via the scrim without discarding the typed value, and only re-seeds from the model when nothing is pending', function () {
    $html = Blade::render('<x-mobile.numeric-pad model="amount" :currency="true" label="Amount" />');

    // padDismiss() (used by the scrim tap) never touches `pad` or `padDirty`.
    expect($html)->toMatch('/padDismiss\(\)\s*\{\s*this\.padOpen = false/');
    // openPad() only re-seeds `pad` from the live model when nothing is dirty.
    expect($html)->toContain('if (!this.padDirty)');
});

it('suppresses the duplicate in-page label for stepper-composed pads, but the sheet header still gets it', function () {
    $stepperHtml = Blade::render('<x-mobile.stepper model="qty" :min="0" :max="10" :integer="true" label="Quantity" />');

    // Stepper already shows its own "Quantity" label above the whole
    // -/pad/+ row, and the pad's sheet header shows it again once opened
    // (spec: header must identify the bound field) — that's 2 total. A
    // 3rd occurrence would mean the pad's own in-page label (redundant
    // with stepper's) wasn't suppressed.
    expect(substr_count($stepperHtml, '>Quantity<'))->toBe(2);

    $standaloneHtml = Blade::render('<x-mobile.numeric-pad model="qty" label="Quantity" />');
    $viaStepperPad = Blade::render('<x-mobile.numeric-pad model="qty" label="Quantity" :hide-field-label="true" />');

    expect($standaloneHtml)->toContain('<div class="text-xs font-bold uppercase text-gray-500 dark:text-gray-400 mb-1">Quantity</div>');
    expect($viaStepperPad)->not->toContain('<div class="text-xs font-bold uppercase text-gray-500 dark:text-gray-400 mb-1">Quantity</div>');
    // The sheet header's own label rendering is untouched either way.
    expect($viaStepperPad)->toContain('<div class="text-xs font-bold uppercase text-gray-400">Quantity</div>');
});

it('cleans up leading zeros while still allowing a legitimate decimal like 0.05', function () {
    $html = Blade::render('<x-mobile.numeric-pad model="amount" label="Amount" />');

    expect($html)->toContain("this.pad = (this.pad === '0' && d !== '.') ? d : (this.pad + d)");
});

it('dispatches global open/close events so the sticky CTA bar can hide while the pad is open', function () {
    $html = Blade::render('<x-mobile.numeric-pad model="amount" label="Amount" />');

    expect($html)->toContain("new CustomEvent('hms-pad-open')");
    expect($html)->toContain("new CustomEvent('hms-pad-close')");
});

it('wires the sticky CTA bar to hide while any pad is open, via a counter that survives two overlapping opens', function () {
    $html = Blade::render('<x-mobile.sticky-cta-bar>Go</x-mobile.sticky-cta-bar>');

    expect($html)->toContain('hmsOpenPads');
    expect($html)->toContain("addEventListener('hms-pad-open'");
    expect($html)->toContain("addEventListener('hms-pad-close'");
    expect($html)->toContain('x-show="hmsOpenPads === 0"');
});

it('scrolls the invoking field into view when the pad opens', function () {
    $html = Blade::render('<x-mobile.numeric-pad model="amount" label="Amount" />');

    expect($html)->toContain('scrollIntoView');
});

/**
 * Regression guard for the actual incident: these are the exact classes
 * the numeric-pad/sticky-cta-bar components depend on that were silently
 * missing from the shipped CSS because it predated the mobile UI/UX pass.
 * If this ever fails again, it means someone added/changed a class here
 * without running `npm run build` and committing the result.
 */
it('ships compiled CSS that actually contains the classes these components depend on', function () {
    $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
    $cssFile = $manifest['resources/css/app.css']['file'] ?? null;

    expect($cssFile)->not->toBeNull();

    $css = file_get_contents(public_path('build/' . $cssFile));

    foreach (['z-\\[70\\]{', 'z-\\[71\\]{', 'bg-black\\/40{', 'translate-y-full{', 'rounded-t-2xl{', 'max-w-md{'] as $needle) {
        expect($css)->toContain($needle);
    }
});
