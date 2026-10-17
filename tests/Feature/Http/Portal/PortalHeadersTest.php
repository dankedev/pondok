<?php

use App\Models\Setting;
use App\Models\User;

beforeEach(function () {
    portalBoot();
    portalSeedContent();
});

dataset('portalCachedUrls', ['/', '/berita', '/berita/wisuda-2026', '/sitemap.xml']);

it('AC-27: sets public cache headers and etag', function (string $url) {
    $response = $this->get($url)->assertOk();
    $cache = $response->headers->get('Cache-Control');
    $etag = $response->headers->get('ETag');

    expect($cache)->toContain('public')->toContain('max-age=300');
    expect($etag)->not->toBeNull();

    $again = $this->withHeader('If-None-Match', $etag)->get($url);
    expect($again->getStatusCode())->toBe(304)->and($again->getContent())->toBe('');
})->with('portalCachedUrls');

it('AC-27: does not publicly cache 404 and honours max age 0', function () {
    expect($this->get('/berita/tidak-ada')->assertNotFound()->headers->get('Cache-Control'))->not->toContain('public');

    config(['pondok.portal.cache_max_age' => 0]);
    $response = $this->get('/')->assertOk();
    expect($response->headers->get('Cache-Control'))->not->toContain('public')
        ->and($response->headers->has('ETag'))->toBeFalse();
});

it('AC-28: keeps portal stateless', function (string $url, int $status) {
    $guest = $this->get($url);
    expect($guest->getStatusCode())->toBe($status)->and($guest->headers->getCookies())->toBeEmpty();

    $user = User::factory()->create();
    $signedIn = $this->actingAs($user)->withUnencryptedCookie('laravel_session', 'abc')->get($url);
    expect($signedIn->getStatusCode())->toBe($status)->and($signedIn->headers->getCookies())->toBeEmpty();
})->with([['/', 200], ['/berita', 200], ['/berita/wisuda-2026', 200], ['/sitemap.xml', 200], ['/berita/tidak-ada', 404], ['/berita?kategori=tidak-ada', 404]]);

it('AC-28: sends no cookies for HEAD and no tokens in portal html', function () {
    expect($this->call('HEAD', '/')->headers->getCookies())->toBeEmpty();
    expect($this->call('HEAD', '/berita')->headers->getCookies())->toBeEmpty();

    foreach (['/', '/berita', '/berita/wisuda-2026'] as $url) {
        $html = strtolower($this->get($url)->assertOk()->getContent());
        foreach (['csrf-token', 'data-csrf', 'xsrf', '_token', 'livewire'] as $needle) {
            expect($html)->not->toContain($needle);
        }
    }
});

it('AC-28: keeps livewire assets and sessions on /psb', function () {
    Setting::set('modules.psb.enabled', true);

    $response = $this->get('/psb')->assertOk();

    expect($response->headers->getCookies())->not->toBeEmpty()
        ->and((string) $response->headers->get('Cache-Control'))->not->toContain('public')
        ->and($response->getContent())->toMatch('/<script[^>]*livewire/i')->toContain('wire:');
});

it('AC-29: sends security headers and csp', function () {
    $response = $this->get('/')->assertOk();
    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($csp)->toContain('frame-src https://www.youtube-nocookie.com')->toContain("script-src 'self'")
        ->toContain("object-src 'none'")->toContain("frame-ancestors 'self'")->not->toContain('unsafe-eval');
});

it('AC-29: allows turning csp off and keeps it out of /admin and /psb', function () {
    config(['pondok.portal.csp_enabled' => false]);
    $off = $this->get('/')->assertOk();
    expect($off->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and($off->headers->get('X-Content-Type-Options'))->toBe('nosniff');

    config(['pondok.portal.csp_enabled' => true]);
    Setting::set('modules.psb.enabled', true);
    expect($this->get('/admin/login')->headers->has('Content-Security-Policy'))->toBeFalse();
    expect($this->get('/psb')->headers->has('Content-Security-Policy'))->toBeFalse();
});
