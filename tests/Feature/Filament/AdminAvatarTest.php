<?php

use App\Filament\AvatarProviders\InitialsAvatarProvider;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Facades\Filament;

/*
| F088: avatar panel admin berupa SVG data URI inisial lokal, tanpa permintaan ke ui-avatars.com.
*/

function avatarSvg(string $url): string
{
    expect($url)->toStartWith('data:image/svg+xml;base64,');

    return base64_decode(substr($url, strlen('data:image/svg+xml;base64,')));
}

/**
 * Rasio kontras WCAG 2.x antara dua warna hex #RRGGBB.
 */
function avatarContrast(string $foreground, string $background): float
{
    $luminance = function (string $hex): float {
        $channels = array_map(function (string $pair): float {
            $value = hexdec($pair) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    };

    [$light, $dark] = collect([$luminance($foreground), $luminance($background)])->sortDesc()->values()->all();

    return ($light + 0.05) / ($dark + 0.05);
}

it('AC-1: renders the admin user menu avatar locally without ui-avatars.com', function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    $admin = User::factory()->create(['name' => 'Ahmad Fauzi'])->assignRole('super-admin');

    $html = $this->actingAs($admin)->get('/admin')->assertOk()->getContent();

    expect($html)->not->toContain('ui-avatars.com')
        ->and(Filament::getUserAvatarUrl($admin))->toStartWith('data:image/svg+xml;base64,');
});

it('AC-2: builds initials from up to two words, including single words and non-ASCII names', function (string $name, string $initials) {
    $svg = avatarSvg(app(InitialsAvatarProvider::class)->get(User::factory()->make(['name' => $name])));

    expect($svg)->toContain(">{$initials}</text>")
        ->and($svg)->not->toContain('http://', 'https://ui-avatars');
})->with([
    'two words' => ['Ahmad Fauzi', 'AF'],
    'three words' => ['muhammad ali hasan', 'MA'],
    'single word' => ['Bendahara', 'B'],
    'non-ASCII' => ['Élan Ñúñez', 'ÉÑ'],
    'Arabic script' => ['عبد الله', 'عا'],
    'leading punctuation' => ['[SYSTEM] Admin', 'SA'],
    'extra whitespace' => ['  Siti   Aisyah ', 'SA'],
    'no letters' => ['---', '?'],
]);

it('AC-3: keeps markup out of the SVG and text contrast at least 4.5:1', function () {
    $svg = avatarSvg(app(InitialsAvatarProvider::class)->get(User::factory()->make(['name' => '<script> &x'])));

    preg_match('/<rect[^>]*fill="(#[0-9A-Fa-f]{6})"/', $svg, $background);

    expect($svg)->not->toContain('<script')
        ->and($svg)->toContain('>SX</text>')
        ->and(avatarContrast('#FFFFFF', $background[1]))->toBeGreaterThanOrEqual(4.5);
});
