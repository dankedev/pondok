<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;

/*
| F046 AC-1 dan AC-2. Kontrak: migrasi `2026_10_20_000001_move_portal_footer_to_widgets.php` (anonymous class,
| `up()` dan `down()`), ditulis dengan `DB::table`. Hasil: `portal.footer_widgets` berisi `{type, visible, data}`:
| `text` {heading, body (HTML), show_logo}, `links` {heading, items}, `social` {heading}, `contacts` {heading}.
| Urutan kandidat: text (deskripsi, show_logo true, selalu dibuat), contacts, social, links (heading "Tautan"),
| text (teks pendek, heading = judul). Lebih dari 4 kandidat: teks pendek digabung ke akhir body widget teks pertama.
*/

beforeEach(function () {
    portalBoot();
    $this->seed(RolePermissionSeeder::class);
    $this->legacyKeys = ['portal.footer_description', 'portal.footer_text', 'portal.footer_show_contacts', 'portal.footer_show_social', 'portal.navigation_footer'];
});

it('AC-1: migrates the legacy footer into four widgets, merging the short text into the first text widget', function () {
    ftrLegacy();

    ftrMigration()->up();

    $widgets = ftrStored();

    expect(array_column($widgets, 'type'))->toBe(['text', 'contacts', 'social', 'links'])
        ->and(array_unique(array_column($widgets, 'visible')))->toBe([true]);

    [$text, , , $links] = $widgets;
    $body = $text['data']['body'];

    expect($text['data']['show_logo'])->toBeTrue()
        ->and($text['data']['heading'] ?? null)->toBeNull()
        ->and($body)->toContain('Deskripsi lembaga uji')->toContain('Jam Layanan')->toContain('Senin sampai Jumat')->toContain('08.00 sampai 15.00')->toContain('<br>')
        ->and(strpos($body, 'Deskripsi lembaga uji'))->toBeLessThan(strpos($body, 'Jam Layanan'))
        ->and(strpos($body, 'Jam Layanan'))->toBeLessThan(strpos($body, 'Senin sampai Jumat'))
        ->and($links['data']['heading'])->toBe('Tautan')
        ->and($links['data']['items'])->toEqual([navItem('url', 'Berita Pilihan', ['url' => '/berita']), navItem('url', 'Situs Mitra', ['url' => 'https://contoh.test/mitra'])]);

    foreach ($this->legacyKeys as $key) {
        expect(profilRawHas($key))->toBeFalse($key);
    }

    // Hasil migrasi lolos validasi tanpa diubah.
    ftrSave($widgets);

    expect(ftrStored())->toEqual($widgets)
        ->and(kontenActivities()->where('description', 'portal_footer_updated'))->toHaveCount(0);
});

it('AC-1: keeps the order as is without a short text, and falls back to the default links when none were saved', function () {
    ftrLegacy(['portal.footer_text' => null, 'portal.navigation_footer' => null]);

    ftrMigration()->up();

    $widgets = ftrStored();

    expect(array_column($widgets, 'type'))->toBe(['text', 'contacts', 'social', 'links'])
        ->and($widgets[0]['data']['body'])->toContain('Deskripsi lembaga uji')
        ->and($widgets[3]['data']['items'])->toEqual([navItem('psb.status'), navItem('letters.verify')]);
});

it('AC-1: turns off widgets for switched-off contacts and social and an empty footer navigation, the short text then stands alone', function () {
    ftrLegacy(['portal.footer_show_contacts' => false, 'portal.footer_show_social' => false, 'portal.navigation_footer' => []]);

    ftrMigration()->up();

    [$description, $short] = ftrStored();

    expect(array_column(ftrStored(), 'type'))->toBe(['text', 'text'])
        ->and($description['data'])->toMatchArray(['show_logo' => true])
        ->and($description['data']['body'])->toContain('Deskripsi lembaga uji')->not->toContain('Jam Layanan')
        ->and($short['data']['show_logo'])->toBeFalse()
        ->and($short['data']['heading'])->toBe('Jam Layanan')
        ->and($short['data']['body'])->toContain('Senin sampai Jumat')->toContain('<br>')->toContain('08.00 sampai 15.00');

    foreach ($this->legacyKeys as $key) {
        expect(profilRawHas($key))->toBeFalse($key);
    }
});

it('AC-1: keeps the legacy maximum values whole (escaped) and the resulting widget valid without changes', function () {
    $nasty = fn (int $length): string => mb_substr(str_repeat('&<"\'', (int) ceil($length / 4)), 0, $length);
    ftrLegacy([
        'portal.footer_description' => $nasty(500),
        'portal.footer_text' => ['title' => $nasty(60), 'body' => $nasty(300)],
        'portal.footer_show_contacts' => true,
    ]);

    ftrMigration()->up();

    $widgets = ftrStored();
    $body = $widgets[0]['data']['body'];

    expect(array_column($widgets, 'type'))->toBe(['text', 'contacts', 'social', 'links'])
        ->and($body)->not->toContain('<i>')->not->toContain('&<')
        ->and(mb_substr_count(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5), '&'))->toBe(125 + 15 + 75);

    ftrSave($widgets);

    expect(ftrStored())->toEqual($widgets);
});

it('AC-2: gives a database without any content no widgets and no changes', function () {
    foreach (['settings', 'pages', 'posts', 'albums', 'videos'] as $table) {
        DB::table($table)->delete();
    }

    ftrMigration()->up();

    expect(profilRawHas('portal.footer_widgets'))->toBeFalse();
});

it('AC-2: is idempotent and never overwrites existing widgets or deletes the old keys then', function () {
    ftrLegacy();
    $existing = [ftr('social', ['heading' => 'Ikuti kami'])];
    ftrSet($existing);

    ftrMigration()->up();

    expect(ftrStored())->toEqual($existing)
        ->and(profilRawHas('portal.footer_description'))->toBeTrue();

    DB::table('settings')->where('key', 'portal.footer_widgets')->delete();
    ftrMigration()->up();
    $first = ftrStored();
    ftrMigration()->up();

    expect($first)->not->toBeEmpty()->and(ftrStored())->toEqual($first)->and(profilRawHas('portal.footer_description'))->toBeFalse();
});

it('AC-2: down only drops the widgets and does not restore the old keys', function () {
    ftrSet([ftr('social')]);
    profilRawSet('portal.footer_description', 'Tetap');

    ftrMigration()->down();

    expect(profilRawHas('portal.footer_widgets'))->toBeFalse()
        ->and(profilRawGet('portal.footer_description'))->toBe('Tetap')
        ->and(profilRawHas('portal.footer_text'))->toBeFalse();
});
