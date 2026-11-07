<?php

use App\Actions\Konten\FetchThemeCatalog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

/*
| F103 AC-1. Kontrak: `FetchThemeCatalog::handle(): array{themes: list<entri>, error: ?string}` tidak pernah melempar.
| Entri keluaran persis berkunci `slug, name, description, version, schema_version, min_core, size, sha256, url, signature, key_id`
| (kunci lain seperti `preview` dibuang). Katalog dibaca lewat `ThemeCatalogClient` dari `config('pondok.theme_catalog_url')`, maks. 256 KB.
| Slug tema resmi = folder di `config('pondok.themes_path')`.
*/

const TC_ENTRY_KEYS = ['slug', 'name', 'description', 'version', 'schema_version', 'min_core', 'size', 'sha256', 'url', 'signature', 'key_id'];

beforeEach(function () {
    themeTestKeys();
    themeSandbox();
    themeInstalledSandbox();
});

function tcFetch(): array
{
    return app(FetchThemeCatalog::class)->handle();
}

function tcValidEntry(string $slug = 'zz-bagus', string $version = '1.0.0'): array
{
    return signedThemePackage($slug, $version)['entry'];
}

it('AC-1: returns the valid entries of the catalog with exactly the documented keys and no error', function () {
    $first = tcValidEntry('zz-satu');
    $second = tcValidEntry('zz-dua', '2.1.0');
    themeFakeCatalog([[...$first, 'preview' => 'https://pondok.org/x.png'], $second]);

    $result = tcFetch();

    expect($result['error'])->toBeNull()
        ->and(array_column($result['themes'], 'slug'))->toBe(['zz-satu', 'zz-dua'])
        ->and(array_keys($result['themes'][0]))->toEqualCanonicalizing(TC_ENTRY_KEYS)
        ->and($result['themes'][0])->toBe(Arr::only($first, TC_ENTRY_KEYS))
        ->and($result['themes'][1]['version'])->toBe('2.1.0');
});

it('AC-1: skips invalid entries (including a traversal slug) without dropping the valid ones', function () {
    $good = tcValidEntry('zz-bagus');
    $broken = [
        'traversal' => [...tcValidEntry(), 'slug' => '../x'],
        'nested path' => [...tcValidEntry(), 'slug' => 'a/../b'],
        'uppercase' => [...tcValidEntry(), 'slug' => 'ZZ-Besar'],
        'too short' => [...tcValidEntry(), 'slug' => 'x'],
        'trailing newline' => [...tcValidEntry(), 'slug' => "zz-newline\n"],
        'slug not a string' => [...tcValidEntry(), 'slug' => 12],
        'bad version' => [...tcValidEntry('zz-v1'), 'version' => 'satu'],
        'bad min_core' => [...tcValidEntry('zz-v2'), 'min_core' => '1.0'],
        'sha256 too short' => [...tcValidEntry('zz-h1'), 'sha256' => 'abc'],
        'size zero' => [...tcValidEntry('zz-s1'), 'size' => 0],
        'size is text' => [...tcValidEntry('zz-s2'), 'size' => '100'],
        'schema is text' => [...tcValidEntry('zz-s3'), 'schema_version' => '1'],
        'no signature' => Arr::except(tcValidEntry('zz-g1'), ['signature']),
        'no key id' => Arr::except(tcValidEntry('zz-k1'), ['key_id']),
        'no url' => Arr::except(tcValidEntry('zz-u1'), ['url']),
        'no name' => Arr::except(tcValidEntry('zz-n1'), ['name']),
        'not an object' => 'zz-teks',
    ];
    themeFakeCatalog([...array_values($broken), $good]);

    $result = tcFetch();

    expect($result['error'])->toBeNull()
        ->and(array_column($result['themes'], 'slug'))->toBe(['zz-bagus']);
});

it('AC-1: ignores an entry that uses the slug of an official theme', function () {
    themeFakeCatalog([tcValidEntry('majalah'), tcValidEntry('default'), tcValidEntry('zz-bagus')]);

    expect(array_column(tcFetch()['themes'], 'slug'))->toBe(['zz-bagus']);
});

it('F111 AC-8: filters out lokal-* entries and keeps the other valid ones', function () {
    themeFakeCatalog([tcValidEntry('lokal-sendiri'), tcValidEntry('zz-bagus'), tcValidEntry('lokal-a-b')]);

    $result = tcFetch();

    expect(array_column($result['themes'], 'slug'))->toBe(['zz-bagus'])
        ->and($result['error'])->toBeNull();
});

it('AC-1: reports an error without throwing for a 500, a timeout, broken JSON and a wrong shape', function () {
    $failures = [
        'status 500' => fn () => themeFakeHttp([THEME_CATALOG_URL => Http::response('SECRET-ISI-RESPONS', 500)]),
        'status 404' => fn () => themeFakeHttp([THEME_CATALOG_URL => Http::response('SECRET-ISI-RESPONS', 404)]),
        'timeout' => fn () => themeFakeHttp([THEME_CATALOG_URL => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]),
        'broken json' => fn () => themeFakeHttp([THEME_CATALOG_URL => Http::response('{"themes": [SECRET-ISI-RESPONS', 200)]),
        'themes is text' => fn () => themeFakeHttp([THEME_CATALOG_URL => Http::response('{"themes": "SECRET-ISI-RESPONS"}', 200)]),
        'no themes key' => fn () => themeFakeHttp([THEME_CATALOG_URL => Http::response('{"daftar": []}', 200)]),
        'root is a list' => fn () => themeFakeHttp([THEME_CATALOG_URL => Http::response('[]', 200)]),
    ];

    foreach ($failures as $label => $arrange) {
        $arrange();
        $result = tcFetch();

        expect($result['themes'])->toBe([], $label)
            ->and($result['error'])->toBeString()->not->toBe('', $label)
            ->and($result['error'])->not->toContain('SECRET-ISI-RESPONS', $label);
    }
});

it('AC-1: stops a body above 256 KB that has no Content-Length and reports an error', function () {
    $oversized = json_encode(['themes' => [], 'padding' => str_repeat('a', 256 * 1024)]);
    themeFakeHttp([THEME_CATALOG_URL => Http::response($oversized, 200)]);

    $result = tcFetch();

    expect(strlen($oversized))->toBeGreaterThan(256 * 1024)
        ->and($result['themes'])->toBe([])
        ->and($result['error'])->toBeString()->not->toBe('');
});

it('AC-1: accepts an empty catalog as a valid answer', function () {
    themeFakeCatalog([]);

    expect(tcFetch())->toBe(['themes' => [], 'error' => null]);
});
