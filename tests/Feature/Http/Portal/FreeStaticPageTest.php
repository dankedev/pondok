<?php

use App\Models\Setting;
use App\Models\StaticPage;

/*
| F037 AC-14 sampai AC-17a: halaman bebas, routing, dan halaman sistem mengikuti status.
*/

beforeEach(function () {
    portalBoot();
});

/**
 * Isi halaman tanpa header dan footer (untuk memeriksa tautan "terkait").
 */
function portalMainHtml(string $html): string
{
    return (string) preg_replace(['#<header\b.*?</header>#is', '#<footer\b.*?</footer>#is'], '', $html);
}

it('AC-14: serves only published free pages', function () {
    staticPage(['slug' => 'pengurus', 'title' => 'Pengurus Yayasan', 'body' => '<p onclick="x()">Isi pengurus</p><script>alert(1)</script>']);
    staticPage(['slug' => 'rahasia', 'title' => 'Rahasia', 'status' => 'draft']);

    $html = $this->get('/pengurus')->assertOk()->getContent();

    expect($html)->toMatch('/<h1[^>]*>\s*Pengurus Yayasan\s*<\/h1>/')
        ->toContain('Isi pengurus')->not->toContain('<script>alert(1)')->not->toContain('onclick')
        ->toContain('<link rel="canonical" href="'.url('/pengurus').'"');

    $this->get('/rahasia')->assertNotFound();
    $this->get('/tidak-ada')->assertNotFound();
    $this->get('/Pengurus')->assertNotFound();
    $this->get('/'.str_repeat('a', 51))->assertNotFound();

    Setting::set('modules.konten.enabled', false);
    $this->get('/pengurus')->assertNotFound();
});

it('AC-15: does not shadow reserved routes', function () {
    staticPage(['slug' => 'pengurus']);
    Setting::set('modules.psb.enabled', true);
    Setting::set('modules.dokumen.enabled', true);
    portalPsbOpen();

    $this->get('/admin')->assertRedirect();
    $this->get('/up')->assertOk();
    expect($this->get('/sitemap.xml')->assertOk()->headers->get('Content-Type'))->toStartWith('application/xml')
        ->and($this->get('/robots.txt')->assertOk()->headers->get('Content-Type'))->toStartWith('text/plain');
    $this->get('/berita')->assertOk();
    $this->get('/verifikasi-surat')->assertOk();
    $this->get('/psb')->assertOk();
});

it('AC-16: escapes page titles, menu labels and footer labels', function () {
    $x = '<script>alert(1)</script>';
    $page = staticPage(['slug' => 'pengurus', 'title' => $x]);
    navSet([navItem('home'), navPage($page, $x)]);
    ftrSet([ftr('links', ['items' => [navItem('url', $x, ['url' => '/berita']), navPage($page, $x)]])]);

    foreach (['/', '/pengurus'] as $url) {
        $html = $this->get($url)->assertOk()->getContent();

        expect($html)->not->toContain('<script>alert(1)')->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
    }

    expect(portalNavTexts($this->get('/')->getContent()))->toContain($x);
});

describe('system pages follow their status', function () {
    it('AC-17: hides a draft contact page and its menu item', function () {
        portalContactData();
        StaticPage::findBySlug('kontak')->update(['status' => 'draft']);

        $this->get('/kontak')->assertNotFound();
        expect(portalNavTexts($this->get('/')->getContent()))->not->toContain('Kontak');
    });

    it('AC-17: hides the home profile section and links when the profile page is a draft', function () {
        StaticPage::findBySlug('profil')->update(['status' => 'draft', 'body' => '<p>Isi profil</p>']);

        $html = $this->get('/')->assertOk()->getContent();

        expect($html)->not->toContain('seksi-profil')->not->toMatch('#href="[^"]*/profil"#');
        $this->get('/profil')->assertNotFound();
    });

    it('AC-9 (F048): profile and vision pages carry no built-in related link to each other', function () {
        StaticPage::findBySlug('profil')->update(['body' => '<p>Isi profil</p>']);
        StaticPage::findBySlug('visi-misi')->update(['title' => 'Visi Kami', 'body' => '<p>Isi visi</p>']);

        expect(portalMainHtml($this->get('/profil')->assertOk()->getContent()))->not->toContain('/visi-misi')->not->toContain('Visi Kami');
        expect(portalMainHtml($this->get('/visi-misi')->assertOk()->getContent()))->not->toContain('/profil');
    });
});

it('AC-17a: the contact page needs contact data', function () {
    StaticPage::findBySlug('kontak')->update(['body' => '<p>Jam layanan</p>']);

    $this->get('/kontak')->assertNotFound();
    expect(portalNavTexts($this->get('/')->getContent()))->not->toContain('Kontak');

    ftrSet([ftr('links', ['items' => [navPage(StaticPage::findBySlug('kontak'))]])]);
    expect(profilFooter($this->get('/')->getContent()))->not->toContain('/kontak');
    expect($this->get('/sitemap.xml')->getContent())->not->toContain('/kontak');
});

it('AC-17a: any single contact datum makes the contact page available', function (array $settings) {
    StaticPage::findBySlug('kontak')->update(['body' => '<p>Jam layanan</p>']);
    profilSet($settings);

    $this->get('/kontak')->assertOk()->assertSee(__('pondok.portal.contact.title'));
    expect(portalNavTexts($this->get('/')->getContent()))->toContain('Kontak');
})->with([
    'email only' => [['school.email' => 'info@contoh.test']],
    'map only' => [['school.map_url' => 'https://maps.app.goo.gl/abc']],
    'one contact' => [['school.contacts' => [['key' => 'abcd1234', 'label' => 'Panitia', 'name' => null, 'phone' => '081200000001', 'is_whatsapp' => true]]]],
]);

/*
| F048 AC-7 dan AC-8: perilaku kontak mengikuti template, bukan slug.
*/

it('AC-7 (F048): a contact-template page on any slug shows its body and the contact card, and is 404 without contact data', function () {
    staticPage(['slug' => 'hubungi-kami', 'title' => 'Hubungi Kami', 'body' => '<p>Jam layanan</p>', 'template' => 'contact']);

    $this->get('/hubungi-kami')->assertNotFound();

    portalContactData();

    $this->get('/hubungi-kami')->assertOk()->assertSee('Jam layanan')->assertSee(__('pondok.portal.contact.title'))->assertSee('info@contoh.test');
});

it('AC-7 (F048): a default-template page with the slug kontak is a plain page, with or without contact data', function () {
    StaticPage::findBySlug('kontak')->update(['template' => 'default', 'body' => '<p>Halaman biasa</p>']);

    $this->get('/kontak')->assertOk()->assertSee('Halaman biasa')->assertDontSee(__('pondok.portal.contact.title'));

    portalContactData();

    $this->get('/kontak')->assertOk()->assertDontSee(__('pondok.portal.contact.title'));
});

it('AC-8 (F048): menu and sitemap hide a contact-template page without contact data and show it with data, whatever its slug', function () {
    $page = staticPage(['slug' => 'hubungi-kami', 'title' => 'Hubungi Kami', 'body' => '<p>Jam layanan</p>', 'template' => 'contact']);
    navSet([navItem('home'), navPage($page)]);

    expect(portalNavTexts($this->get('/')->getContent()))->not->toContain('Hubungi Kami');
    expect($this->get('/sitemap.xml')->getContent())->not->toContain('/hubungi-kami');

    portalContactData();

    expect(portalNavTexts($this->get('/')->getContent()))->toContain('Hubungi Kami');
    expect($this->get('/sitemap.xml')->getContent())->toContain('/hubungi-kami');
});

it('AC-8 (F048): a default-template page with the slug kontak is treated as a normal page in menu and sitemap even without contact data', function () {
    $page = StaticPage::findBySlug('kontak');
    $page->update(['template' => 'default', 'body' => '<p>Halaman biasa</p>']);
    navSet([navItem('home'), navPage($page)]);

    expect(portalNavTexts($this->get('/')->getContent()))->toContain('Kontak');
    expect($this->get('/sitemap.xml')->getContent())->toContain('/kontak');
});
