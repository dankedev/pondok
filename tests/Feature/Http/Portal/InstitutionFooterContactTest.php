<?php

use App\Actions\Konten\FormatPhoneForDisplay;
use App\Http\Middleware\SetPortalSecurityHeaders;
use App\Models\Setting;
use App\Models\StaticPage;

beforeEach(function () {
    portalBoot();
    Setting::set('school.name', "Ma'had Al-Khair");
});

it('AC-48: renders the description, contacts, socials and footer links through widgets (F046)', function () {
    profilSet([
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
    ftrSet([
        ftr('text', ['body' => '<p>Deskripsi lembaga uji</p>', 'show_logo' => true]), ftr('contacts'), ftr('social'),
        ftr('links', ['items' => [navItem('url', 'Berita Pilihan', ['url' => '/berita']), navItem('url', 'Info PSB', ['url' => 'https://contoh.test/psb'])]]),
    ]);

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

it('AC-6 (F044): shows address, email, map and every contact exactly once, with no separate phone row, in the footer and on /kontak', function () {
    StaticPage::findBySlug('kontak')->update(['body' => '']);
    profilSet([
        'school.address' => 'Jl. Pesantren No. 1', 'school.email' => 'info@alkhair.sch.id', 'school.map_url' => 'https://maps.app.goo.gl/abc',
        'school.contacts' => [
            profilContact(['key' => 'aaaa1111', 'label' => 'Panitia', 'phone' => '081234567890', 'is_print' => false]),
            profilContact(['key' => 'bbbb2222', 'label' => 'Kantor', 'phone' => '0735000170', 'is_whatsapp' => false, 'is_print' => true]),
        ],
    ]);

    ftrSet([ftr('contacts')]);

    $footer = profilVisibleText(profilFooter($this->get('/')->assertOk()->getContent()));
    $html = $this->get('/kontak')->assertOk()->getContent();
    preg_match('#<main\b.*?</main>#is', $html, $main);
    $page = profilVisibleText($main[0] ?? '');

    expect($page)->not->toBeEmpty();

    foreach ([$footer, $page] as $text) {
        expect(substr_count($text, 'Jl. Pesantren No. 1'))->toBe(1)
            ->and(substr_count($text, 'info@alkhair.sch.id'))->toBe(1)
            ->and(substr_count($text, '0812-3456-7890'))->toBe(1)
            ->and(substr_count($text, app(FormatPhoneForDisplay::class)->handle('0735000170')))->toBe(1)
            ->and(substr_count($text, 'Lihat di peta'))->toBe(1);
    }
});

it('AC-6 (F044): ignores a leftover school.phone and keeps the contact page for address only', function () {
    StaticPage::findBySlug('kontak')->update(['body' => '']);
    profilRawSet('school.phone', '081200009999');

    $this->get('/kontak')->assertNotFound();

    Setting::set('school.address', 'Jl. Pesantren No. 1');

    $text = profilVisibleText($this->get('/kontak')->assertOk()->getContent());

    expect($text)->toContain('Jl. Pesantren No. 1')->not->toContain('0812-0000-9999')->not->toContain('081200009999');
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
    ftrSet([ftr('contacts')]);
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
    ftrSet([ftr('links', ['items' => [navPage($page), navPage($page, 'Tentang')]])]);

    $footer = profilFooter($this->get('/')->assertOk()->getContent());

    expect($footer)->toMatch('#<a\b[^>]*href="[^"]*/pengurus"[^>]*>\s*Pengurus Yayasan\s*</a>#s')
        ->toMatch('#<a\b[^>]*href="[^"]*/pengurus"[^>]*>\s*Tentang\s*</a>#s');

    $page->update(['slug' => 'pengurus-yayasan']);

    expect(profilFooter($this->get('/')->getContent()))->toContain('/pengurus-yayasan')->not->toMatch('#/pengurus"#');
});

it('AC-29 (F037): drops page links of draft pages, deleted pages and a disabled content module', function () {
    $page = staticPage(['slug' => 'pengurus', 'title' => 'Pengurus Yayasan']);
    ftrSet([ftr('links', ['items' => [navPage($page)]])]);

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
