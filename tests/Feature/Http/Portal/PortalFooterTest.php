<?php

use App\Models\Setting;

/*
| F043 AC-11 dan AC-12. Kontrak footer: `<footer>` generik berisi nama, deskripsi, kontak (saklar `footer_show_contacts`),
| media sosial (`footer_show_social`), kolom navigasi footer berjudul `pondok.portal.footer.links` ("Tautan"),
| teks pendek (`portal.footer_text`: judul + isi dengan `whitespace-pre-line`, teks polos ter-escape), dan hak cipta.
| Tidak ada markup kutipan. Footer ringkas (route `psb.*` dan 404): hanya nama, navigasi footer, dan hak cipta.
*/

beforeEach(function () {
    portalBoot();
    Setting::set('school.name', "Ma'had Al-Khair");
    Setting::set('modules.psb.enabled', false);
    Setting::set('modules.dokumen.enabled', false);
});

/**
 * Isi lengkap footer untuk membandingkan footer penuh dan ringkas.
 */
function footerFill(): void
{
    profilSet([
        'portal.footer_description' => 'Deskripsi lembaga uji',
        'portal.footer_text' => ['title' => 'Jam Layanan', 'body' => "Senin sampai Jumat\n08.00 sampai 15.00"],
        'school.contacts' => [profilContact(['label' => 'Panitia Uji', 'phone' => '081234567890'])],
        'school.social_links' => [['platform' => 'instagram', 'label' => null, 'url' => 'https://instagram.com/alkhair']],
    ]);
    navSet(null, [navItem('url', 'Berita Pilihan', ['url' => '/berita']), navItem('url', 'Situs Mitra', ['url' => 'https://contoh.test/mitra'])]);
}

it('AC-11: renders the name, description, contacts, socials, footer links, short text and copyright', function () {
    footerFill();

    $footer = profilFooter($this->get('/')->assertOk()->getContent());
    $text = profilVisibleText($footer);

    expect($text)->toContain("Ma'had Al-Khair")->toContain('Deskripsi lembaga uji')
        ->toContain('Panitia Uji')->toContain('0812-3456-7890')->toContain(__('pondok.portal.social.instagram'))
        ->toContain(__('pondok.portal.footer.links'))->toContain('Berita Pilihan')->toContain('Situs Mitra')
        ->toContain('Jam Layanan')->toContain('Senin sampai Jumat')->toContain('08.00 sampai 15.00')
        ->toContain(__('pondok.portal.footer.rights', ['year' => now()->year, 'name' => "Ma'had Al-Khair"]))
        ->and($footer)->toContain('href="/berita"')->toContain('href="https://contoh.test/mitra"')->toContain('href="https://instagram.com/alkhair"');
});

it('AC-11: shows no quote markup even when an archived quote exists', function () {
    footerFill();
    Setting::set('portal.archived_footer_quote', ['arabic' => 'نص عربي للاختبار', 'translation' => 'Terjemah arsip', 'source' => 'Sumber arsip']);
    profilRawSet('portal.footer_quote_arabic', 'نص عربي للاختبار');
    profilRawSet('portal.footer_quote_translation', 'Terjemah lama');

    $footer = profilFooter($this->get('/')->assertOk()->getContent());

    expect($footer)->not->toContain('<blockquote')->not->toContain('<figure')->not->toContain('lang="ar"')
        ->not->toContain('Terjemah arsip')->not->toContain('Terjemah lama')->not->toContain('Sumber arsip');
});

it('AC-11: the contact and social switches hide their blocks independently', function (bool $contacts, bool $social) {
    footerFill();
    Setting::set('portal.footer_show_contacts', $contacts);
    Setting::set('portal.footer_show_social', $social);

    $text = profilVisibleText(profilFooter($this->get('/')->assertOk()->getContent()));

    expect(str_contains($text, 'Panitia Uji'))->toBe($contacts)
        ->and(str_contains($text, __('pondok.portal.social.instagram')))->toBe($social)
        ->and($text)->toContain('Deskripsi lembaga uji')->toContain('Berita Pilihan');
})->with([[true, true], [false, true], [true, false], [false, false]]);

it('AC-11: renders no empty sections when only the name is known', function () {
    navSet(null, []);

    $footer = profilFooter($this->get('/')->assertOk()->getContent());

    expect(profilVisibleText($footer))->toContain("Ma'had Al-Khair")->not->toContain(__('pondok.portal.footer.links'))->not->toContain('Jam Layanan')
        ->and($footer)->not->toContain('<blockquote')->not->toMatch('#<(ul|nav)\b[^>]*>\s*</(ul|nav)>#');
});

it('AC-11: escapes the short text and keeps its line breaks', function () {
    Setting::set('portal.footer_text', ['title' => '<script>alert(1)</script>', 'body' => "Baris satu\nBaris <b>dua</b>"]);
    navSet(null, [navItem('url', '<script>alert(2)</script>', ['url' => '/berita'])]);

    $footer = profilFooter($this->get('/')->assertOk()->getContent());

    expect($footer)->not->toContain('<script>alert')->not->toContain('<b>dua')
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')->toContain('&lt;b&gt;dua&lt;/b&gt;')
        ->toMatch('#whitespace-pre-line[^>]*>\s*Baris satu\nBaris#');
});

it('AC-11: a body-only short text renders without a heading', function () {
    Setting::set('portal.footer_text', ['title' => null, 'body' => 'Hanya isi pendek']);

    expect(profilVisibleText(profilFooter($this->get('/')->assertOk()->getContent())))->toContain('Hanya isi pendek');
});

it('AC-11: the footer has no hidden system links beyond the saved footer navigation', function () {
    Setting::set('modules.psb.enabled', true);
    Setting::set('modules.dokumen.enabled', true);
    navSet(null, [navItem('url', 'Satu', ['url' => '/berita'])]);

    $footer = profilFooter($this->get('/')->assertOk()->getContent());

    expect(profilVisibleText($footer))->not->toContain(__('pondok.portal.footer.psb_status'))->not->toContain(__('pondok.portal.footer.verify_letter'));
});

it('AC-3: the default footer navigation lists the status check and the letter verification', function () {
    Setting::set('modules.psb.enabled', true);
    Setting::set('modules.dokumen.enabled', true);

    $footer = profilFooter($this->get('/')->assertOk()->getContent());

    expect($footer)->toContain('href="'.route('psb.status').'"')->toContain('href="'.route('dokumen.letters.verify-form').'"')
        ->and(profilVisibleText($footer))->toContain('Cek status pendaftaran')->toContain('Verifikasi keaslian surat');
});

it('AC-12: uses the compact footer on the psb pages and the 404 page', function (string $path, int $status) {
    portalPsbOpen();
    footerFill();

    $response = $this->get($path)->assertStatus($status);
    $footer = profilFooter($response->getContent());
    $text = profilVisibleText($footer);

    expect($footer)->not->toBe('')
        ->and($text)->toContain("Ma'had Al-Khair")->toContain('Berita Pilihan')->toContain('Situs Mitra')
        ->toContain(__('pondok.portal.footer.rights', ['year' => now()->year, 'name' => "Ma'had Al-Khair"]))
        ->not->toContain('Deskripsi lembaga uji')->not->toContain('Panitia Uji')->not->toContain('0812-3456-7890')
        ->not->toContain(__('pondok.portal.social.instagram'))->not->toContain('Jam Layanan')
        ->and($footer)->not->toContain('href="https://instagram.com/alkhair"');
})->with([['/psb', 200], ['/psb/status', 200], ['/halaman-yang-tidak-ada', 404]]);

it('AC-12: other portal pages keep the full footer', function (string $path) {
    portalSeedContent();
    footerFill();

    $text = profilVisibleText(profilFooter($this->get($path)->assertOk()->getContent()));

    expect($text)->toContain('Deskripsi lembaga uji')->toContain('Panitia Uji')->toContain('Jam Layanan')->toContain('Berita Pilihan');
})->with(['/', '/berita', '/galeri', '/video', '/profil']);
