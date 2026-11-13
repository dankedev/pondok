<?php

use App\Actions\Konten\ThemeCatalogClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
| F103 AC-7 dan AC-12 (cek statis). Kontrak: `ThemeCatalogClient::get(string $url, int $maxBytes): string` mengembalikan tubuh respons 200,
| atau melempar `RuntimeException` (pesan generik, tanpa isi respons). Host yang diizinkan = host `config('pondok.theme_catalog_url')`.
| Satu-satunya kelas aplikasi yang memakai `Http::`.
*/

const TC_ALLOWED = 'https://pondok.org/themes/zz-uji/1.0.0/theme.json';

beforeEach(function () {
    config(['pondok.theme_catalog_url' => THEME_CATALOG_URL]);
});

function tcGet(string $url, int $max = 65536): string
{
    return app(ThemeCatalogClient::class)->get($url, $max);
}

function tcRedirect(string $location, int $status = 302): mixed
{
    return Http::response('', $status, ['Location' => $location]);
}

it('AC-7: downloads the body of an allowed https url on the catalog host', function () {
    themeFakeHttp([TC_ALLOWED => Http::response('{"ok":true}', 200)]);

    expect(tcGet(TC_ALLOWED))->toBe('{"ok":true}');
});

it('AC-7: refuses a url that is not https, on another host, an ip literal, with userinfo, or on another port, before any request', function () {
    $refused = [
        'http scheme' => 'http://pondok.org/themes/x/1.0.0/theme.json',
        'other host' => 'https://evil.example/themes/x/1.0.0/theme.json',
        'sub domain' => 'https://cdn.pondok.org/themes/x/1.0.0/theme.json',
        'look-alike host' => 'https://pondok.org.evil.example/themes/x/1.0.0/theme.json',
        'ipv4 literal' => 'https://203.0.113.9/themes/x/1.0.0/theme.json',
        'ipv6 literal' => 'https://[2001:db8::1]/themes/x/1.0.0/theme.json',
        'userinfo' => 'https://admin:rahasia@pondok.org/themes/x/1.0.0/theme.json',
        'user only' => 'https://admin@pondok.org/themes/x/1.0.0/theme.json',
        'port 8443' => 'https://pondok.org:8443/themes/x/1.0.0/theme.json',
        'port 80' => 'https://pondok.org:80/themes/x/1.0.0/theme.json',
        'file scheme' => 'file:///etc/passwd',
        'no scheme' => 'pondok.org/themes/x/1.0.0/theme.json',
    ];

    themeFakeHttp(['*' => Http::response('tidak boleh terkirim', 200)]);

    foreach ($refused as $label => $url) {
        expect(fn () => tcGet($url))->toThrow(RuntimeException::class);
    }

    Http::assertNothingSent();
});

it('AC-7: refuses an ip literal even when the catalog url itself is configured as one', function () {
    config(['pondok.theme_catalog_url' => 'https://203.0.113.9/themes/themes.json']);
    themeFakeHttp(['*' => Http::response('{}', 200)]);

    expect(fn () => tcGet('https://203.0.113.9/themes/themes.json'))->toThrow(RuntimeException::class);

    Http::assertNothingSent();
});

it('AC-7: follows a redirect on the same host, and resolves a relative Location before checking it', function () {
    themeFakeHttp([
        TC_ALLOWED => tcRedirect('/themes/zz-uji/pindah/theme.json'),
        'https://pondok.org/themes/zz-uji/pindah/theme.json' => tcRedirect('akhir.json'),
        'https://pondok.org/themes/zz-uji/pindah/akhir.json' => Http::response('isi akhir', 200),
    ]);

    expect(tcGet(TC_ALLOWED))->toBe('isi akhir');

    Http::assertSentCount(3);
});

it('AC-7: allows three redirect hops and refuses the fourth', function () {
    $chain = fn (int $hops) => themeFakeHttp([
        'https://pondok.org/h0' => $hops >= 1 ? tcRedirect('https://pondok.org/h1') : Http::response('sampai', 200),
        'https://pondok.org/h1' => $hops >= 2 ? tcRedirect('https://pondok.org/h2') : Http::response('sampai', 200),
        'https://pondok.org/h2' => $hops >= 3 ? tcRedirect('https://pondok.org/h3') : Http::response('sampai', 200),
        'https://pondok.org/h3' => $hops >= 4 ? tcRedirect('https://pondok.org/h4') : Http::response('sampai', 200),
        'https://pondok.org/h4' => Http::response('sampai', 200),
    ]);

    $chain(3);
    expect(tcGet('https://pondok.org/h0'))->toBe('sampai');

    $chain(4);
    expect(fn () => tcGet('https://pondok.org/h0'))->toThrow(RuntimeException::class);
});

it('AC-7: refuses a redirect to another scheme, host, port or userinfo and never requests it', function () {
    $targets = [
        'other host' => 'https://evil.example/curi.json',
        'scheme-relative other host' => '//evil.example/curi.json',
        'http scheme' => 'http://pondok.org/curi.json',
        'other port' => 'https://pondok.org:8443/curi.json',
        'userinfo' => 'https://admin:rahasia@pondok.org/curi.json',
        'ip literal' => 'https://203.0.113.9/curi.json',
        'file scheme' => 'file:///etc/passwd',
    ];

    foreach ($targets as $label => $location) {
        themeFakeHttp([
            TC_ALLOWED => tcRedirect($location),
            '*curi.json' => Http::response('dicuri', 200),
            'https://203.0.113.9/*' => Http::response('dicuri', 200),
        ]);

        expect(fn () => tcGet(TC_ALLOWED))->toThrow(RuntimeException::class);

        Http::assertSentCount(1);
    }
});

it('AC-7: refuses a redirect without a Location header and a non-200 answer, without leaking the body', function () {
    themeFakeHttp([TC_ALLOWED => Http::response('', 302)]);
    expect(fn () => tcGet(TC_ALLOWED))->toThrow(RuntimeException::class);

    foreach ([404, 500, 204] as $status) {
        themeFakeHttp([TC_ALLOWED => Http::response('SECRET-ISI-RESPONS', $status)]);

        try {
            tcGet(TC_ALLOWED);
            $this->fail("status {$status} harus ditolak");
        } catch (RuntimeException $e) {
            expect($e->getMessage())->not->toContain('SECRET-ISI-RESPONS');
        }
    }
});

it('AC-7: turns a connection failure into a plain RuntimeException', function () {
    themeFakeHttp([TC_ALLOWED => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

    expect(fn () => tcGet(TC_ALLOWED))->toThrow(RuntimeException::class);
});

it('AC-7: stops reading a body that grows past the limit without a Content-Length, and accepts one exactly at the limit', function () {
    themeFakeHttp([TC_ALLOWED => Http::response(str_repeat('a', 1001), 200)]);
    expect(fn () => tcGet(TC_ALLOWED, 1000))->toThrow(RuntimeException::class);

    themeFakeHttp([TC_ALLOWED => Http::response(str_repeat('a', 1000), 200)]);
    expect(strlen(tcGet(TC_ALLOWED, 1000)))->toBe(1000);
});

it('AC-7: refuses a Content-Length above the limit even when the real body is small', function () {
    themeFakeHttp([TC_ALLOWED => Http::response('kecil', 200, ['Content-Length' => '5000000'])]);

    expect(fn () => tcGet(TC_ALLOWED, 65536))->toThrow(RuntimeException::class);
});

it('AC-12: only ThemeCatalogClient talks to the network through Http', function () {
    $users = collect(File::allFiles(app_path()))
        ->filter(fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.php')
            && preg_match('/\bHttp::|Illuminate\\\\Support\\\\Facades\\\\Http\b|Illuminate\\\\Http\\\\Client\\\\Factory\b|\bcurl_init\b/', $file->getContents()) === 1)
        ->map(fn (SplFileInfo $file): string => $file->getFilename())
        ->values()->all();

    expect($users)->toBe(['ThemeCatalogClient.php']);
});
