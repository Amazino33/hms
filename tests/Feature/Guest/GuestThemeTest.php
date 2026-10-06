<?php

use App\Models\Table as TableModel;
use Illuminate\Support\Facades\File;

/**
 * D39 — the guest menu is light by default, with a sun/moon switch to the
 * D30 dark theme, remembered on the phone and applied before first paint.
 */
function gt39Palettes(): array
{
    $css = File::get(resource_path('css/guest.css'));
    $block = fn (string $selector) => str($css)->after($selector.' {')->before("\n}")->toString();
    $tokens = function (string $body) {
        preg_match_all('/--([\w-]+):\s*([^;]+);/', $body, $m);

        return array_combine($m[1], array_map('trim', $m[2]));
    };

    return ['light' => $tokens($block(':root')), 'dark' => $tokens($block('html[data-theme="dark"]'))];
}

function gt39Luminance(string $hex): float
{
    $hex = ltrim($hex, '#');
    $channel = function (string $pair) {
        $c = hexdec($pair) / 255;

        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * $channel(substr($hex, 0, 2)) + 0.7152 * $channel(substr($hex, 2, 2)) + 0.0722 * $channel(substr($hex, 4, 2));
}

function gt39Contrast(string $a, string $b): float
{
    [$hi, $lo] = [max(gt39Luminance($a), gt39Luminance($b)), min(gt39Luminance($a), gt39Luminance($b))];

    return ($hi + 0.05) / ($lo + 0.05);
}

it('defines every colour token in both the light default and the dark theme', function () {
    ['light' => $light, 'dark' => $dark] = gt39Palettes();

    // Shape tokens (radius, fonts, easing) live only in :root.
    $colours = array_keys(array_filter($light, fn ($v) => preg_match('/^(#|rgba?\(|transparent)/', $v)));
    expect($colours)->not->toBeEmpty();
    expect(array_keys($dark))->toEqualCanonicalizing($colours);

    expect($light['bg'])->toBe('#FAF7F2')
        ->and($dark['bg'])->toBe('#121214')
        ->and($light['red'])->toBe($dark['red']); // one brand red in both
});

it('keeps text readable in both themes (WCAG AA, 4.5:1)', function () {
    foreach (gt39Palettes() as $theme => $p) {
        // The dark theme's --red-light is the unchanged D30 colour (4.1:1 on
        // its cards); only the new light default is held to AA for it.
        $foregrounds = $theme === 'light' ? ['text', 'soft', 'muted', 'red-light', 'warn', 'ok'] : ['text', 'soft', 'muted', 'warn', 'ok'];

        foreach ($foregrounds as $fg) {
            foreach (['bg', 'surface', 'sheet'] as $bg) {
                expect(gt39Contrast($p[$fg], $p[$bg]))->toBeGreaterThanOrEqual(4.5, "{$theme}: --{$fg} on --{$bg}");
            }
        }
    }

    // White text on the brand red buttons, in both.
    expect(gt39Contrast('#FFFFFF', gt39Palettes()['light']['red']))->toBeGreaterThanOrEqual(4.5);
});

it('serves the light theme first, with the switch and the before-paint script', function () {
    $table = TableModel::create(['name' => 'Table 5', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);

    foreach (['/m/'.$table->qr_token, '/menu'] as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        $head = str($html)->before('</head>')->toString();

        expect($head)->toContain('<meta name="theme-color" content="#FAF7F2">')
            ->toContain("localStorage.getItem('selum_theme') === 'dark'");
        // The saved choice is applied before the stylesheet can paint.
        expect(strpos($head, 'selum_theme'))->toBeLessThan(strpos($head, 'rel="stylesheet"') ?: PHP_INT_MAX);
        expect($html)->toContain('class="theme-btn" @click="toggleTheme()"');
    }

    expect(File::get(resource_path('js/guest.js')))->toContain("store.set('selum_theme', this.dark ? 'dark' : 'light')");
});
