<?php

use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->f = portalBoot();
});

it('VERIFY: never lists or opens a terbit post whose published_at is NULL', function () {
    portalPost(['title' => 'Terbit Tanpa Tanggal', 'published_at' => null]);

    $this->get('/berita')->assertOk()->assertDontSee('Terbit Tanpa Tanggal');
    $this->get('/berita/terbit-tanpa-tanggal')->assertNotFound();
    expect($this->get('/sitemap.xml')->getContent())->not->toContain('terbit-tanpa-tanggal');
    $this->get('/')->assertDontSee('Terbit Tanpa Tanggal');
});

it('VERIFY: does not render dangerous urls from settings as links', function () {
    Setting::set('school.social_links', [
        ['platform' => 'instagram', 'label' => null, 'url' => 'javascript:alert(1)'],
        ['platform' => 'facebook', 'label' => null, 'url' => 'data:text/html,<script>alert(1)</script>'],
        ['platform' => 'youtube', 'label' => null, 'url' => '"><script>alert(1)</script>'],
    ]);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toContain('javascript:')->not->toContain('data:text/html')->not->toContain('<script>alert(1)');
});

it('VERIFY: escapes contact data and never leaks markup', function () {
    Setting::set('school.phone', '"><script>alert(1)</script>');
    Setting::set('school.address', '<img src=x onerror=alert(1)>');
    Setting::set('school.email', '"><b>x</b>');

    foreach (['/', '/kontak'] as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        expect($html)->not->toContain('<script>alert(1)')->not->toContain('<img src=x onerror')->not->toContain('"><b>x</b>');
    }
});

it('VERIFY: re-sanitizes hostile body markup', function () {
    portalPost(['title' => 'Berbahaya', 'body' => '<a href="javascript:alert(1)">klik</a><iframe src="https://evil.test"></iframe><svg onload="alert(1)"></svg><style>body{display:none}</style><form action="https://evil.test"><input></form><p>aman</p>']);

    $html = $this->get('/berita/berbahaya')->assertOk()->getContent();
    preg_match('/portal-prose.*?<\/div>/s', $html, $prose);
    expect($prose[0] ?? '')->not->toContain('display:none')->not->toContain('<style');

    expect($html)->not->toContain('javascript:alert')->not->toContain('evil.test')->not->toContain('onload')
        ->not->toContain('<form action')->toContain('<p>aman</p>');
});

it('VERIFY: ignores non-youtube urls on posts', function () {
    portalPost(['title' => 'Video Palsu', 'youtube_url' => 'https://evil.test/watch?v=dQw4w9WgXcQ']);
    portalPost(['title' => 'Video Js', 'youtube_url' => 'javascript:alert(1)']);

    foreach (['video-palsu', 'video-js'] as $slug) {
        $html = $this->get("/berita/{$slug}")->assertOk()->getContent();
        expect($html)->not->toContain('<iframe')->not->toContain('javascript:alert')->not->toContain('evil.test');
    }
});

it('VERIFY: does not reflect hostile query values', function () {
    $html = $this->get('/berita?kategori=%3Cscript%3Ealert(1)%3C/script%3E')->assertNotFound()->getContent();

    expect($html)->not->toContain('<script>alert(1)');
    $this->get('/berita/%E2%80%AE')->assertNotFound();
});

it('VERIFY: does not swallow other routes with the static page route', function () {
    Setting::set('modules.psb.enabled', true);
    Setting::set('modules.dokumen.enabled', true);

    $this->get('/admin/login')->assertOk();
    $this->get('/psb')->assertOk();
    $this->get('/psb/status')->assertOk();
    $this->get('/verifikasi-surat')->assertOk();
    $this->get('/admin/tidak-ada')->assertStatus(404);
    $this->get('/berita/')->assertOk();
    $this->get('/BERITA')->assertNotFound();
    $this->get('/Profil')->assertNotFound();
});

it('VERIFY: keeps csrf and livewire on /psb while portal pages stay token free', function () {
    Setting::set('modules.psb.enabled', true);

    $psb = $this->get('/psb')->assertOk()->getContent();
    expect($psb)->toMatch('/data-csrf=|csrf-token/i');

    $this->get('/psb')->assertCookie(config('session.cookie'));
});

it('VERIFY: 404 for authenticated admin and /admin does not error', function () {
    $this->seed(RolePermissionSeeder::class);
    $user = User::factory()->create()->assignRole('super-admin');

    $this->actingAs($user)->get('/tidak-ada-halaman')->assertNotFound();
    $this->actingAs($user)->get('/admin/tidak-ada')->assertNotFound();
});

it('VERIFY: hides konten links from /psb navigation when konten is off', function () {
    Setting::set('modules.psb.enabled', true);
    Setting::set('modules.konten.enabled', false);

    $this->get('/psb')->assertOk()->assertDontSee(route('portal.posts.index'), false)->assertDontSee(route('portal.albums.index'), false);
});

it('VERIFY: keeps security headers on 304 and public cache only on GET and HEAD 200', function () {
    $etag = $this->get('/')->headers->get('ETag');

    $notModified = $this->withHeader('If-None-Match', $etag)->get('/');
    expect($notModified->getStatusCode())->toBe(304)
        ->and($notModified->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($notModified->headers->getCookies())->toBeEmpty();

    expect($this->get('/berita?page=99')->assertNotFound()->headers->get('Cache-Control'))->not->toContain('public');
});

it('VERIFY: robots.txt blocks private paths', function () {
    $robots = $this->get('/robots.txt')->getContent();

    expect($robots)->toContain('Disallow: /psb/berkas/')->toContain('User-agent: *');
});

it('VERIFY: list and home pages do not run N+1 queries', function () {
    $count = function (string $url): int {
        $n = 0;
        DB::listen(function () use (&$n): void {
            $n++;
        });
        $this->get($url)->assertOk();

        return $n;
    };

    $cat = kontenCategory('post', 'Tahfidz');
    portalPost(['title' => 'Satu', 'category_id' => $cat->id, 'unit_id' => $this->f->sltp->id]);
    portalAlbumWithPhotos(2, ['title' => 'Alb Satu']);
    $listFew = $count('/berita');
    $homeFew = $count('/');
    $galFew = $count('/galeri');

    foreach (range(2, 9) as $i) {
        $c = kontenCategory('post', "Kat {$i}");
        portalPost(['title' => "Post {$i}", 'category_id' => $c->id, 'unit_id' => $this->f->sd->id, 'type' => $i % 2 ? 'berita' : 'prestasi']);
        portalAlbumWithPhotos(2, ['title' => "Alb {$i}"]);
    }

    expect($count('/berita'))->toBeLessThanOrEqual($listFew + 1)
        ->and($count('/'))->toBeLessThanOrEqual($homeFew + 2)
        ->and($count('/galeri'))->toBeLessThanOrEqual($galFew + 1);
});

it('VERIFY: prestasi posts never expose achievement meta or student names', function () {
    $student = Student::factory()->create(['name' => 'Zainab Rahmawati']);
    portalPost(['title' => 'Juara Lomba', 'type' => 'prestasi', 'meta' => ['participants' => ['Zainab Rahmawati'], 'nis' => $student->nis]]);

    foreach (['/', '/berita?jenis=prestasi', '/berita/juara-lomba', '/sitemap.xml'] as $url) {
        expect($this->get($url)->assertOk()->getContent())->not->toContain('Zainab Rahmawati')->not->toContain((string) $student->nis);
    }
});
