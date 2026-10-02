<?php

use App\Models\Table as TableModel;
use Illuminate\Support\Facades\File;

/**
 * Quick fix: the phone keyboard must never hide guest search (D31).
 * Guest pages ask Android to shrink the layout for the keyboard; the
 * search panel is full-screen with its box pinned at the top.
 */
it('lets the keyboard resize guest pages only — not admin or kiosk pages', function () {
    $table = TableModel::create(['name' => 'Table 5', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);

    $viewport = fn (string $html) => str($html)->match('/<meta name="viewport" content="([^"]*)">/')->toString();

    foreach (['/m/'.$table->qr_token, '/menu'] as $url) {
        $content = $viewport($this->get($url)->assertOk()->getContent());
        expect($content)->toContain('interactive-widget=resizes-content')->toContain('viewport-fit=cover');
    }
    expect($viewport($this->get('/m/NOTAREALCODE')->assertNotFound()->getContent()))->toContain('interactive-widget=resizes-content');

    // Staff pages keep their own viewport.
    expect($viewport($this->get('/admin/login')->getContent()))->not->toContain('interactive-widget');
    expect($viewport($this->get('/kiosk/register')->getContent()))->not->toContain('interactive-widget');
    expect(File::get(resource_path('views/layouts/kiosk.blade.php')))->not->toContain('interactive-widget');
});

it('renders search as a top-pinned panel: input first, results below, labelled buttons', function () {
    $table = TableModel::create(['name' => 'Table 5', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);

    $html = $this->get('/m/'.$table->qr_token)->assertOk()->getContent();
    $panel = str($html)->between('<div class="search-panel"', '{{-- ')->toString() ?: str($html)->after('<div class="search-panel"')->toString();

    $input = strpos($panel, 'class="search-input"');
    $results = strpos($panel, 'class="search-results"');
    expect($input)->not->toBeFalse();
    expect($results)->not->toBeFalse();
    expect($input)->toBeLessThan($results);

    expect($panel)->toContain('aria-label="Close search"')
        ->toContain('aria-label="Clear search"')
        ->toContain('role="dialog"');

    // The Search tap focuses inside the tap itself (iOS keyboard rule).
    expect($html)->toContain('@click="openSearch()"');
    expect(File::get(resource_path('js/guest.js')))->toMatch('/openSearch\(\) \{[^}]*searchInput\.focus\(\{ preventScroll: true \}\)/s');
});

it('keeps bottom sheets above the keyboard and hides the bottom stack while typing', function () {
    $css = File::get(resource_path('css/guest.css'));
    $js = File::get(resource_path('js/guest.js'));

    expect($css)->toContain('bottom: var(--kb, 0px)')
        ->toContain('max-height: calc(88dvh - var(--kb, 0px))')
        ->toContain('html.kb-open .bottom { display: none; }');
    expect($js)->toContain('window.visualViewport')
        ->toContain("root.style.setProperty('--kb'")
        ->toContain("scrollIntoView({ block: 'center' })");
});
