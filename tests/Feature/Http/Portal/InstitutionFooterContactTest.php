<?php

use App\Http\Middleware\SetPortalSecurityHeaders;
use App\Models\Setting;
use App\Models\StaticPage;

beforeEach(function () {
    portalBoot();
    Setting::set('school.name', "Ma'had Al-Khair");
});

it('AC-48: renders the description, contacts, socials and footer links (the quote is gone, F043)', function () {
    profilSet([
        'portal.footer_description' => 'Deskripsi lembaga uji',
        'school.contacts' => [
            profilContact(['key' => 'aaaa1111', 'label' => 'Panitia', 'name' => 'Ust. Ahmad', 'phone' => '081234567890', 'is_whatsapp' => true]),
            profilContact(['key' => 'bbbb2222', 'label' => 'Telepon Kantor', 'phone' => '0735000170', 'is_whatsapp' => false]),
        ],
        'school.social_links' => [
            ['platform' => 'instagram', 'label' => null, 'url' => 'https://instagram.com/alkhair'],
            ['platform' => 'youtube', 'label' => null, 'url' => 'https://youtube.com/@alkhair'],
            ['platform' => 'lainnya', 'label' => 'Blog Kami', 'url' => 'https://blog.contoh.test'],
        ],
    ]);
    navSet(null, [navItem('url', 'Berita Pilihan', ['url' => '/berita']), navItem('url', 'Info PSB', ['url' => 'https://contoh.test/psb'])]);

    $footer = profilFooter($this->get('/')->assertOk()->getContent());
    $text = profilVisibleText($footer);

    expect($text)->toContain('Deskripsi lembaga uji')
        ->toContain('Panitia')->toContain('0812-3456-7890')->toContain('Telepon Kantor')
        ->toContain(__('pondok.portal.social.instagram'))->toContain(__('pondok.portal.social.youtube'))->toContain('Blog Kami')
        ->and(__('pondok.portal.social.instagram'))->not->toBe('pondok.portal.social.instagram')
        ->and($footer)->toContain('href="https://wa.me/6281234567890"')->toContain('href="tel:0735000170"')
        ->toContain('href="https://instagram.com/alkhair"')->toContain('href="https://blog.contoh.test"')
        ->toContain('href="/berita"')->toContain('href="https://contoh.test/psb"');

    // F037: tautan visi-misi bawaan dihapus dari footer; hanya tautan pilihan admin yang tampil.
    expect($footer)->not->toContain(route('portal.pages.show', 'visi-misi'))
        ->and(strpos($footer, 'Berita Pilihan'))->not->toBeFalse();
});

it('AC-48: does not render the old whatsapp and social keys', function () {
    profilRawSet('school.whatsapp', '081200000000');
    profilRawSet('school.instagram_url', 'https://instagram.com/lama');

    $footer = profilFooter($this->get('/')->assertOk()->getContent());

    expect($footer)->not->toContain('wa.me/62812000000')->not->toContain('instagram.com/lama');
});

it('AC-49: shows the map as a plain link only, in the footer and on the contact page', function () {
    StaticPage::findBySlug('kontak')->update(['body' => '']);
    Setting::set('school.map_url', 'https://maps.app.goo.gl/abc');
    config(['pondok.portal.csp_enabled' => true]);

    foreach (['/', '/kontak'] as $url) {
        $response = $this->get($url)->assertOk();
        $html = $response->getContent();
        $link = profilTag($html, 'a', ['href="https://maps.app.goo.gl/abc"']);

        expect(__('pondok.portal.contact.map_link'))->toBe('Lihat di peta')
            ->and($link)->not->toBeNull()->toContain('target="_blank"')->toMatch('/rel="[^"]*noopener/')
            ->and(profilVisibleText($html))->toContain('Lihat di peta')
            ->and($html)->not->toContain('<iframe')->not->toContain('maps.googleapis')->not->toContain('google.com/maps/embed')
            ->and($response->headers->get('Content-Security-Policy'))->toBe(SetPortalSecurityHeaders::CONTENT_SECURITY_POLICY);
    }
});

it('AC-49: renders no map link when the map url is empty or unsafe', function (?string $stored) {
    StaticPage::findBySlug('kontak')->update(['body' => '']);
    profilRawSet('school.map_url', $stored);

    // F037: tanpa data kontak lain, /kontak 404 (aturan Kontak).
    $this->get('/kontak')->assertNotFound();
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toContain('Lihat di peta')->not->toContain('javascript:')->not->toContain('<iframe');
})->with(['empty' => [null], 'unsafe' => ['javascript:alert(1)']]);

it('AC-50: shows the contact card on an empty contact page with only contacts', function () {
    StaticPage::findBySlug('kontak')->update(['body' => '']);
    Setting::set('school.contacts', [profilContact(['label' => 'Panitia Uji', 'phone' => '081200000001'])]);

    $response = $this->get('/kontak')->assertOk();
    $text = profilVisibleText($response->getContent());

    expect($text)->toContain('Panitia Uji')->toContain('0812-0000-0001')->toContain(__('pondok.portal.contact.title'))
        ->not->toContain(__('pondok.portal.pages.empty'))
        ->and($response->getContent())->toContain('href="https://wa.me/6281200000001"');
});

it('AC-50: shows the contact card on an empty contact page with only a map link', function () {
    StaticPage::findBySlug('kontak')->update(['body' => '']);
    Setting::set('school.map_url', 'https://maps.app.goo.gl/abc');

    $text = profilVisibleText($this->get('/kontak')->assertOk()->getContent());

    expect($text)->toContain('Lihat di peta')->toContain(__('pondok.portal.contact.title'))->not->toContain(__('pondok.portal.pages.empty'));
});

it('AC-50: returns 404 for an empty contact page without any contact data', function () {
    StaticPage::findBySlug('kontak')->update(['body' => '']);

    $this->get('/kontak')->assertNotFound();
});

it('AC-50: still renders the contact page when only legacy keys remain in settings', function () {
    StaticPage::findBySlug('kontak')->update(['body' => '']);
    profilRawSet('school.whatsapp', '081200000000');

    // F037: kunci lama bukan data kontak, jadi halaman tidak tersedia.
    $this->get('/kontak')->assertNotFound();
});

it('AC-29 (F037): renders page links that follow slug and status', function () {
    $page = staticPage(['slug' => 'pengurus', 'title' => 'Pengurus Yayasan']);
    navSet(null, [navPage($page), navPage($page, 'Tentang')]);

    $footer = profilFooter($this->get('/')->assertOk()->getContent());

    expect($footer)->toMatch('#<a\b[^>]*href="[^"]*/pengurus"[^>]*>\s*Pengurus Yayasan\s*</a>#s')
        ->toMatch('#<a\b[^>]*href="[^"]*/pengurus"[^>]*>\s*Tentang\s*</a>#s');

    $page->update(['slug' => 'pengurus-yayasan']);

    expect(profilFooter($this->get('/')->getContent()))->toContain('/pengurus-yayasan')->not->toMatch('#/pengurus"#');
});

it('AC-29 (F037): drops page links of draft pages, deleted pages and a disabled content module', function () {
    $page = staticPage(['slug' => 'pengurus', 'title' => 'Pengurus Yayasan']);
    navSet(null, [navPage($page)]);

    $page->update(['status' => 'draft']);
    expect(profilFooter($this->get('/')->getContent()))->not->toContain('/pengurus');

    $page->update(['status' => 'terbit']);
    Setting::set('modules.konten.enabled', false);
    expect(profilFooter($this->get('/')->getContent()))->not->toContain('/pengurus');

    Setting::set('modules.konten.enabled', true);
    $page->delete();
    expect(profilFooter($this->get('/')->assertOk()->getContent()))->not->toContain('/pengurus');
});

it('AC-29 (F037): has no vision link in the footer when the footer links are empty', function () {
    expect(StaticPage::findBySlug('visi-misi')->status)->toBe('terbit');

    expect(profilFooter($this->get('/')->assertOk()->getContent()))->not->toContain('/visi-misi');
});
