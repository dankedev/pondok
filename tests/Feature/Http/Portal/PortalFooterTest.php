<?php

use App\Models\Setting;

/*
| F046 AC-3, AC-6, AC-7. Kontrak footer: `<footer>` penuh berisi widget `portal.footer_widgets` sesuai urutan
| (heading widget sebagai `<h2>`), lalu baris hak cipta (`pondok.portal.footer.rights`). Widget `text` dengan
| `show_logo`: tile logo (`<img>` dari `school.logo_mobile_path`) dan nama lembaga; `links` dibungkus `<nav>` dengan
| `aria-labelledby` (ke id heading) atau `aria-label` bila heading kosong; `contacts` dan `social` menampilkan semua
| data Kontak & sosial. Grid memakai kelas literal `lg:grid-cols-{n}` (n = jumlah widget tampil). Footer ringkas
| (`psb.*` dan 404): nama, `compactLinks` sebaris, hak cipta.
*/

beforeEach(function () {
    portalBoot();
    Setting::set('school.name', "Ma'had Al-Kitab");
    Setting::set('modules.psb.enabled', false);
    Setting::set('modules.dokumen.enabled', false);
});

/**
 * Footer lengkap lewat widget untuk membandingkan footer penuh dan ringkas.
 */
function footerFill(): void
{
    profilSet([
        'school.contacts' => [profilContact(['label' => 'Panitia Uji', 'phone' => '081234567890'])],
        'school.social_links' => [['platform' => 'instagram', 'label' => null, 'url' => 'https://instagram.com/pondokcontoh']],
    ]);
    ftrSet([
        ftr('text', ['body' => '<p>Deskripsi lembaga uji</p>', 'show_logo' => true]),
        ftr('contacts', ['heading' => 'Hubungi']),
        ftr('social', ['heading' => 'Ikuti']),
        ftr('links', ['heading' => 'Tautan', 'items' => [navItem('url', 'Berita Pilihan', ['url' => '/berita']), navItem('url', 'Situs Mitra', ['url' => 'https://contoh.test/mitra'])]]),
    ]);
}

function footerHtml(string $path = '/'): string
{
    return profilFooter(test()->get($path)->assertOk()->getContent());
}

it('AC-6: renders the widgets in order with their headings, then the copyright', function () {
    footerFill();

    $footer = footerHtml();
    $text = profilVisibleText($footer);
    $positions = array_map(fn (string $needle): int|false => strpos($text, $needle), ['Deskripsi lembaga uji', 'Hubungi', 'Panitia Uji', 'Ikuti', 'Tautan', 'Berita Pilihan']);

    expect($text)->toContain("Ma'had Al-Kitab")->toContain('0812-3456-7890')->toContain(__('pondok.portal.social.instagram'))->toContain('Situs Mitra')
        ->toContain(__('pondok.portal.footer.rights', ['year' => now()->year, 'name' => "Ma'had Al-Kitab"]))
        ->and($positions)->each->not->toBeFalse()
        ->and($positions)->toBe(collect($positions)->sort()->values()->all())
        ->and($footer)->toMatch('#<h2\b[^>]*>\s*Hubungi\s*</h2>#')->toContain('href="/berita"')->toContain('href="https://contoh.test/mitra"')->toContain('href="https://instagram.com/pondokcontoh"');
});

it('AC-6: shows the logo tile only when the text widget asks for it', function (bool $showLogo) {
    Setting::set('school.logo_mobile_path', 'school/logo-uji.png');
    ftrSet([ftr('text', ['body' => '<p>Isi</p>', 'show_logo' => $showLogo])]);

    expect(str_contains(footerHtml(), 'school/logo-uji.png'))->toBe($showLogo);
})->with([true, false]);

it('AC-6: skips hidden and empty widgets without leaving headings or empty lists', function () {
    ftrSet([
        ftr('text', ['heading' => 'Teks Tersembunyi', 'body' => '<p>Rahasia</p>', 'show_logo' => false], visible: false),
        ftr('text', ['heading' => 'Teks Kosong', 'body' => null, 'show_logo' => false]),
        ftr('links', ['heading' => 'Tautan Hilang', 'items' => [navPage(99999), navPage(staticPage(['status' => 'draft']))]]),
        ftr('contacts', ['heading' => 'Kontak Kosong']),
        ftr('social', ['heading' => 'Sosial Kosong']),
        ftr('text', ['heading' => 'Satu-satunya', 'body' => '<p>Tampil</p>', 'show_logo' => false]),
    ]);

    $footer = footerHtml();
    $text = profilVisibleText($footer);

    expect($text)->toContain('Satu-satunya')->toContain('Tampil')
        ->not->toContain('Teks Tersembunyi')->not->toContain('Rahasia')->not->toContain('Teks Kosong')->not->toContain('Tautan Hilang')
        ->not->toContain('Kontak Kosong')->not->toContain('Sosial Kosong')
        ->and($footer)->not->toMatch('#<(ul|nav)\b[^>]*>\s*</(ul|nav)>#')->toContain('lg:grid-cols-1');
});

it('AC-6: sizes the grid by the number of shown widgets with literal classes', function (int $shown) {
    $widgets = array_map(fn (int $i): array => ftr('text', ['heading' => "Teks {$i}", 'body' => '<p>Isi</p>', 'show_logo' => false]), range(1, $shown));
    ftrSet([...$widgets, ftr('text', ['body' => '<p>Tersembunyi</p>', 'show_logo' => false], visible: false)]);

    $footer = footerHtml();

    // Empat widget: dua kolom sampai 1279px, empat kolom mulai xl (1280px).
    $expected = $shown === 4 ? 'xl:grid-cols-4' : "lg:grid-cols-{$shown}";

    expect($footer)->toContain($expected);

    foreach (array_diff([1, 2, 3, 4], [$shown]) as $other) {
        expect($footer)->not->toContain("lg:grid-cols-{$other}")->not->toContain("xl:grid-cols-{$other}");
    }

    if ($shown === 4) {
        expect($footer)->not->toContain('lg:grid-cols-4');
    }
})->with([1, 2, 3, 4]);

it('AC-6: the contacts and social widgets always show all current Kontak & sosial data', function () {
    profilSet([
        'school.address' => 'Jl. Pesantren No. 1', 'school.email' => 'info@pondokcontoh.sch.id',
        'school.contacts' => [profilContact(['key' => 'aaaa1111', 'label' => 'Panitia', 'phone' => '081234567890']), profilContact(['key' => 'bbbb2222', 'label' => 'Kantor', 'phone' => '0735000170', 'is_whatsapp' => false])],
        'school.social_links' => [['platform' => 'instagram', 'label' => null, 'url' => 'https://instagram.com/pondokcontoh'], ['platform' => 'lainnya', 'label' => 'Blog Kami', 'url' => 'https://blog.contoh.test']],
    ]);
    ftrSet([ftr('contacts'), ftr('social')]);

    $text = profilVisibleText(footerHtml());

    expect($text)->toContain('Jl. Pesantren No. 1')->toContain('info@pondokcontoh.sch.id')->toContain('Panitia')->toContain('Kantor')
        ->toContain(__('pondok.portal.social.instagram'))->toContain('Blog Kami');

    profilSet(['school.contacts' => [profilContact(['key' => 'cccc3333', 'label' => 'Baru Saja', 'phone' => '081200000009'])]]);

    $text = profilVisibleText(footerHtml());

    expect($text)->toContain('Baru Saja')->not->toContain('Panitia')->not->toContain('Kantor');
});

it('AC-6: without widgets the full footer only has the copyright line', function () {
    $footer = footerHtml();

    expect(profilVisibleText($footer))->toContain(__('pondok.portal.footer.rights', ['year' => now()->year, 'name' => "Ma'had Al-Kitab"]))
        ->and($footer)->not->toMatch('#<(ul|nav|h2)\b#');
});

it('AC-6: the links widget is a labelled nav, by its heading or by an aria-label when it has none', function () {
    ftrSet([ftr('links', ['heading' => 'Tautan Penting', 'items' => [navItem('url', 'Berita', ['url' => '/berita'])]])]);

    $footer = footerHtml();
    $nav = profilTag($footer, 'nav', ['aria-labelledby="']);
    preg_match('/aria-labelledby="([^"]+)"/', (string) $nav, $id);

    expect($nav)->not->toBeNull()->and($id[1] ?? '')->not->toBe('')
        ->and($footer)->toMatch('#<h2\b[^>]*\bid="'.preg_quote($id[1] ?? 'x', '#').'"[^>]*>\s*Tautan Penting#');

    ftrSet([ftr('links', ['heading' => null, 'items' => [navItem('url', 'Berita', ['url' => '/berita'])]])]);

    $footer = footerHtml();

    expect(profilTag($footer, 'nav', ['aria-label="']))->not->toBeNull()->and($footer)->not->toContain('aria-labelledby');
});

it('AC-6: sanitizes widgets written straight to settings and escapes headings and labels', function () {
    ftrSet([
        ftr('text', ['heading' => '<script>alert(0)</script>', 'body' => '<p>Aman</p><script>alert(1)</script><a href="javascript:alert(2)">klik</a>', 'show_logo' => false]),
        ftr('links', ['items' => [
            navItem('url', 'Jahat', ['url' => '//evil.test']), navItem('url', 'Skrip', ['url' => 'javascript:alert(3)']),
            navItem('url', '<b>Tebal</b>', ['url' => '/berita']),
        ]]),
    ]);

    $footer = footerHtml();

    expect(profilVisibleText($footer))->toContain('Aman')->toContain('<script>alert(0)</script>')->toContain('<b>Tebal</b>')
        ->and($footer)->not->toContain('<script>alert')->not->toContain('javascript:')->not->toContain('evil.test')->not->toContain('<b>Tebal')
        ->toContain('href="/berita"');
});

it('AC-7: uses the compact footer on the psb pages and the 404 page', function (string $path, int $status) {
    portalPsbOpen();
    profilSet(['school.contacts' => [profilContact(['label' => 'Panitia Uji', 'phone' => '081234567890'])]]);
    ftrSet([
        ftr('links', ['heading' => 'Semua Hilang', 'items' => [navPage(99999)]]),
        ftr('links', ['heading' => 'Tautan', 'items' => [navItem('url', 'Berita Pilihan', ['url' => '/berita']), navItem('url', 'Situs Mitra', ['url' => 'https://contoh.test/mitra'])]]),
        ftr('text', ['heading' => 'Tentang', 'body' => '<p>Deskripsi lembaga uji</p>', 'show_logo' => true]),
        ftr('contacts', ['heading' => 'Hubungi']),
    ]);

    $footer = profilFooter($this->get($path)->assertStatus($status)->getContent());
    $text = profilVisibleText($footer);

    expect($footer)->not->toBe('')
        ->and($text)->toContain("Ma'had Al-Kitab")->toContain('Berita Pilihan')->toContain('Situs Mitra')
        ->toContain(__('pondok.portal.footer.rights', ['year' => now()->year, 'name' => "Ma'had Al-Kitab"]))
        ->not->toContain('Deskripsi lembaga uji')->not->toContain('Panitia Uji')->not->toContain('0812-3456-7890')->not->toContain('Semua Hilang')->not->toContain('Tentang')->not->toContain('Hubungi');
})->with([['/psb', 200], ['/psb/status', 200], ['/halaman-yang-tidak-ada', 404]]);

it('AC-7: other portal pages keep the full footer', function (string $path) {
    portalSeedContent();
    footerFill();

    $text = profilVisibleText(footerHtml($path));

    expect($text)->toContain('Deskripsi lembaga uji')->toContain('Panitia Uji')->toContain('Berita Pilihan');
})->with(['/', '/berita', '/galeri', '/video', '/profil']);

it('AC-3: after the migration the footer shows the same name, logo, description, contacts, socials, links and short text', function () {
    Setting::set('school.logo_mobile_path', 'school/logo-uji.png');
    profilSet([
        'school.contacts' => [profilContact(['label' => 'Panitia Uji', 'phone' => '081234567890'])],
        'school.social_links' => [['platform' => 'instagram', 'label' => null, 'url' => 'https://instagram.com/pondokcontoh']],
    ]);
    ftrLegacy();

    ftrMigration()->up();
    app()->forgetScopedInstances();

    $footer = footerHtml();
    $text = profilVisibleText($footer);
    $positions = array_map(fn (string $needle): int|false => strpos($text, $needle), ['Deskripsi lembaga uji', 'Jam Layanan', 'Senin sampai Jumat', 'Panitia Uji', __('pondok.portal.social.instagram'), 'Berita Pilihan', 'Situs Mitra']);

    expect($text)->toContain("Ma'had Al-Kitab")->toContain('0812-3456-7890')->toContain('08.00 sampai 15.00')
        ->and($footer)->toContain('school/logo-uji.png')->toContain('href="/berita"')->toContain('href="https://contoh.test/mitra"')
        ->and($positions)->each->not->toBeFalse()
        ->and(strpos($text, 'Berita Pilihan'))->toBeLessThan(strpos($text, 'Situs Mitra'));
});
