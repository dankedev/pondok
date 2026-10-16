<?php

use App\Http\Middleware\SetPortalSecurityHeaders;
use App\Models\Setting;
use App\Models\StaticPage;

beforeEach(function () {
    portalBoot();
    Setting::set('school.name', "Ma'had Al-Khair");
});

it('AC-48: renders the footer quote, contacts, socials and quick links', function () {
    profilSet([
        'portal.footer_quote_arabic' => 'نص عربي للاختبار',
        'portal.footer_quote_translation' => 'Terjemah uji',
        'portal.footer_quote_source' => 'Sumber uji 1',
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
        'portal.footer_links' => [['label' => 'Berita Pilihan', 'url' => '/berita'], ['label' => 'Info PSB', 'url' => 'https://contoh.test/psb']],
    ]);

    $footer = profilFooter($this->get('/')->assertOk()->getContent());
    $text = profilVisibleText($footer);

    expect($footer)->toMatch('/<[a-z0-9]+\b(?=[^>]*\blang="ar")(?=[^>]*\bdir="rtl")[^>]*>\s*نص عربي للاختبار/u')
        ->and($text)->toContain('Terjemah uji')->toContain('Sumber uji 1')->toContain('Deskripsi lembaga uji')
        ->toContain('Panitia')->toContain('0812-3456-7890')->toContain('Telepon Kantor')
        ->toContain(__('pondok.portal.social.instagram'))->toContain(__('pondok.portal.social.youtube'))->toContain('Blog Kami')
        ->and(__('pondok.portal.social.instagram'))->not->toBe('pondok.portal.social.instagram')
        ->and($footer)->toContain('href="https://wa.me/6281234567890"')->toContain('href="tel:0735000170"')
        ->toContain('href="https://instagram.com/alkhair"')->toContain('href="https://blog.contoh.test"')
        ->toContain('href="/berita"')->toContain('href="https://contoh.test/psb"');

    $quick = strpos($footer, 'Berita Pilihan');
    $system = strpos($footer, route('portal.pages.show', 'visi-misi'));

    expect($quick)->not->toBeFalse()->and($system)->not->toBeFalse()->and($quick)->toBeLessThan($system);
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

    foreach (['/', '/kontak'] as $url) {
        $html = $this->get($url)->assertOk()->getContent();

        expect($html)->not->toContain('Lihat di peta')->not->toContain('javascript:')->not->toContain('<iframe');
    }
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

it('AC-50: shows the empty state on an empty contact page without any contact data', function () {
    StaticPage::findBySlug('kontak')->update(['body' => '']);

    $text = profilVisibleText($this->get('/kontak')->assertOk()->getContent());

    expect($text)->toContain(__('pondok.portal.pages.empty'))->not->toContain(__('pondok.portal.contact.title'));
});

it('AC-50: still renders the contact page when only legacy keys remain in settings', function () {
    StaticPage::findBySlug('kontak')->update(['body' => '']);
    profilRawSet('school.whatsapp', '081200000000');

    $this->get('/kontak')->assertOk();
});
