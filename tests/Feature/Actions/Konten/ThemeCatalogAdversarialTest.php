<?php

use App\Actions\Konten\InstallPortalTheme;
use App\Actions\Konten\InstallThemeFromCatalog;
use App\Actions\Konten\RemovePortalTheme;
use App\Actions\Konten\RollbackPortalTheme;
use App\Actions\Konten\ThemeCatalogClient;
use App\Filament\Pages\Site\ThemeCatalog;
use App\Jobs\Konten\InstallThemeFromCatalogJob;
use App\Models\Setting;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
| F103 VERIFY (adversarial). Celah yang dicurigai di luar test RED: varian host/redirect pada klien jaringan, kebingungan kunci dan tanda
| tangan atas byte yang berbeda, slug berbentuk licik (newline, null byte, unicode), symlink di folder terpasang, URL entri katalog lintas
| host, izin/modul yang berubah setelah halaman dibuka, XSS pada teks katalog, dan job yang berebut dengan penghapusan.
*/

beforeEach(function () {
    kontenBoot();
    themeSandbox();
    themeInstalledSandbox();
    $this->keys = themeTestKeys(['k1', 'k2']);
    $this->admin = kontenUser('super-admin');
});

function advGet(string $url, int $max = 65536): string
{
    return app(ThemeCatalogClient::class)->get($url, $max);
}

it('client: refuses look-alike hosts and control characters, but accepts case-insensitive scheme/host and explicit port 443', function () {
    $refused = [
        'trailing dot' => 'https://pondok.org./themes/x/1.0.0/theme.json',
        'cyrillic o' => "https://p\u{043E}ndok.org/themes/x/1.0.0/theme.json",
        'punycode' => 'https://xn--pndok-9ve.org/themes/x/1.0.0/theme.json',
        'tab in url' => "https://pondok.org/themes/x\t/theme.json",
        'newline in url' => "https://pondok.org/themes/x\n/theme.json",
        'backslash authority' => 'https://evil.example\\@pondok.org/themes/x/theme.json',
        'encoded at-sign' => 'https://evil.example%40pondok.org/themes/x/theme.json',
        'port in userinfo trick' => 'https://pondok.org:443@evil.example/themes/x/theme.json',
        'suffix host' => 'https://evilpondok.org/themes/x/theme.json',
    ];

    themeFakeHttp(['*' => Http::response('tidak boleh terkirim', 200)]);

    foreach ($refused as $label => $url) {
        expect(fn () => advGet($url))->toThrow(RuntimeException::class, '', $label);
    }

    Http::assertNothingSent();

    foreach (['HTTPS://PONDOK.ORG/themes/x/theme.json', 'https://pondok.org:443/themes/x/theme.json'] as $url) {
        themeFakeHttp(['*' => Http::response('ok', 200)]);

        expect(advGet($url))->toBe('ok', $url);
    }
});

it('client: validates every hop, not just the first, and refuses tricky Location values', function () {
    $start = 'https://pondok.org/themes/zz-uji/1.0.0/theme.json';
    $locations = [
        'look-alike host' => 'https://pondok.org.evil.example/x.json',
        'trailing dot host' => 'https://pondok.org./x.json',
        'backslash path trick' => '/\\evil.example/x.json',
        'scheme-relative look-alike' => '//pondok.org.evil.example/x.json',
        'upper-case http' => 'HTTP://pondok.org/x.json',
        'javascript scheme' => 'javascript:alert(1)',
        'data scheme' => 'data:text/plain;base64,AAAA',
    ];

    foreach ($locations as $label => $location) {
        themeFakeHttp([
            $start => Http::response('', 302, ['Location' => $location]),
            '*x.json' => Http::response('dicuri', 200),
        ]);

        expect(fn () => advGet($start))->toThrow(RuntimeException::class, '', $label);

        Http::assertSentCount(1);
    }

    // Hop kedua ke host lain: hop pertama sah (relatif), hop kedua harus tetap ditolak dan tak pernah diminta.
    themeFakeHttp([
        $start => Http::response('', 301, ['Location' => '/themes/hop2.json']),
        'https://pondok.org/themes/hop2.json' => Http::response('', 307, ['Location' => 'http://pondok.org/themes/hop3.json']),
        '*hop3.json' => Http::response('dicuri', 200),
    ]);

    expect(fn () => advGet($start))->toThrow(RuntimeException::class);

    Http::assertSentCount(2);
});

it('AC-8: a signature made by k2 does not pass for an entry that claims key k1, and malformed key ids or signatures are plain refusals', function () {
    $package = signedThemePackage('zz-uji', '1.0.0', entry: [], keyId: 'k2');
    $before = themeInstalledBaseTree();
    $good = signedThemePackage('zz-uji', '1.0.0');

    $cases = [
        'k2 signature claimed as k1' => [...$package['entry'], 'key_id' => 'k1'],
        'upper-case key id' => [...$good['entry'], 'key_id' => 'K1'],
        'key id with space' => [...$good['entry'], 'key_id' => 'k1 '],
        'key id not in the map' => [...$good['entry'], 'key_id' => 'k3'],
        'key id null' => [...$good['entry'], 'key_id' => null],
        'key id as array' => [...$good['entry'], 'key_id' => ['k1']],
        'key id inherited property name' => [...$good['entry'], 'key_id' => 'constructor'],
        'signature as array' => [...$good['entry'], 'signature' => [$good['entry']['signature']]],
        'signature not base64' => [...$good['entry'], 'signature' => '!!!'.$good['entry']['signature']],
        'signature of 63 bytes' => [...$good['entry'], 'signature' => base64_encode(substr(base64_decode($good['entry']['signature']), 0, 63))],
    ];

    foreach ($cases as $label => $entry) {
        themeAssertRejected(fn () => app(InstallPortalTheme::class)->handle($entry, $good['bytes'], $this->admin));

        expect(themeInstalledBaseTree())->toBe($before, $label);
    }
});

it('AC-8: the signature covers the exact bytes, so whitespace, BOM or CRLF changes are refused even when size and sha256 are recomputed', function () {
    $good = signedThemePackage('zz-uji', '1.0.0');
    $before = themeInstalledBaseTree();

    $variants = [
        'trailing space' => $good['bytes'].' ',
        'extra newline' => $good['bytes']."\n",
        'utf-8 bom' => "\xEF\xBB\xBF".$good['bytes'],
        'crlf' => str_replace("\n", "\r\n", $good['bytes']),
        'leading newline' => "\n".$good['bytes'],
    ];

    foreach ($variants as $label => $bytes) {
        $entry = [...$good['entry'], 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];

        themeAssertRejected(fn () => app(InstallPortalTheme::class)->handle($entry, $bytes, $this->admin), 'signature_invalid');

        expect(themeInstalledBaseTree())->toBe($before, $label);
    }
});

it('AC-8, AC-11: slugs with a trailing newline, null byte, unicode or a slash are refused by every action before any path or key is formed', function () {
    $before = themeInstalledBaseTree();
    $slugs = ["zz-uji\n", "zz-uji\0", "zz-uj\u{0131}", "\u{FF3A}\u{FF3A}-uji", 'zz-uji/', "zz-uji\r"];
    Queue::fake();

    foreach ($slugs as $slug) {
        $label = json_encode($slug);

        themeAssertRejected(fn () => app(InstallThemeFromCatalog::class)->handle($slug, $this->admin), 'invalid_slug');
        themeAssertRejected(fn () => app(RollbackPortalTheme::class)->handle($slug, $this->admin), 'invalid_slug');
        themeAssertRejected(fn () => app(RemovePortalTheme::class)->handle($slug, $this->admin), 'invalid_slug');

        expect(themeInstalledBaseTree())->toBe($before, $label)
            ->and(Setting::query()->where('key', 'like', 'themes.install.%')->count())->toBe(0, $label);
    }

    Queue::assertNothingPushed();
});

it('AC-11: removing a theme whose folder is a symlink never deletes files outside the theme folder', function () {
    $outside = dirname(themeInstalledSandbox()).'/outside';
    mkdir($outside, 0777, true);
    file_put_contents("{$outside}/penting.txt", 'AMAN');
    file_put_contents("{$outside}/theme.json", json_encode([...themeBaseManifest(), 'slug' => 'zz-uji', 'version' => '1.0.0']));

    if (! @symlink($outside, themeInstalledSandbox().'/zz-uji')) {
        $this->markTestSkipped('symlink tidak tersedia');
    }

    app(RemovePortalTheme::class)->handle('zz-uji', $this->admin);

    expect(file_get_contents("{$outside}/penting.txt"))->toBe('AMAN')
        ->and(is_file("{$outside}/theme.json"))->toBeTrue();
});

it('AC-8, AC-10: installing onto a symlinked slug folder does not write outside the theme folder', function () {
    $outside = dirname(themeInstalledSandbox()).'/outside';
    mkdir($outside, 0777, true);
    file_put_contents("{$outside}/penting.txt", 'AMAN');
    $before = md5_file("{$outside}/penting.txt");

    if (! @symlink($outside, themeInstalledSandbox().'/zz-uji')) {
        $this->markTestSkipped('symlink tidak tersedia');
    }

    $package = signedThemePackage('zz-uji');

    try {
        app(InstallPortalTheme::class)->handle($package['entry'], $package['bytes']);
    } catch (Throwable) {
        // Penolakan boleh; yang dilarang adalah tulis ke luar.
    }

    expect(scandir($outside))->toBe(['.', '..', 'penting.txt'])
        ->and(md5_file("{$outside}/penting.txt"))->toBe($before);
});

it('AC-6, AC-7: a catalog entry whose url points to another host, scheme, port or userinfo fails the job without ever requesting it', function () {
    $package = signedThemePackage('zz-uji', '1.0.0');
    $urls = [
        'other host' => 'https://evil.example/themes/zz-uji/1.0.0/theme.json',
        'look-alike host' => 'https://pondok.org.evil.example/themes/zz-uji/1.0.0/theme.json',
        'http' => 'http://pondok.org/themes/zz-uji/1.0.0/theme.json',
        'port' => 'https://pondok.org:8443/themes/zz-uji/1.0.0/theme.json',
        'userinfo' => 'https://a:b@pondok.org/themes/zz-uji/1.0.0/theme.json',
    ];

    foreach ($urls as $label => $url) {
        Setting::set('themes.install.zz-uji', ['state' => 'queued', 'token' => 't1', 'message' => null, 'at' => now()->toIso8601String()]);
        themeFakeHttp([
            THEME_CATALOG_URL => Http::response(json_encode(['themes' => [[...$package['entry'], 'url' => $url]]]), 200),
            '*' => Http::response($package['bytes'], 200),
        ]);

        app()->call([new InstallThemeFromCatalogJob('zz-uji', 't1', $this->admin->id), 'handle']);

        expect(Setting::query()->find('themes.install.zz-uji')->value['state'])->toBe('failed', $label)
            ->and(themeInstalledTree())->toBe([], $label);

        Http::assertSentCount(1);
    }
});

it('AC-5, AC-11: removing a theme while its install job is queued drops the status so the job does nothing', function () {
    themeInstalledWrite('zz-uji', [...themeBaseManifest(), 'version' => '1.0.0']);
    Setting::set('themes.install.zz-uji', ['state' => 'queued', 'token' => 't1', 'message' => null, 'at' => now()->toIso8601String()]);
    $package = signedThemePackage('zz-uji', '2.0.0');
    themeFakeHttp([
        THEME_CATALOG_URL => Http::response(json_encode(['themes' => [$package['entry']]]), 200),
        $package['entry']['url'] => Http::response($package['bytes'], 200),
    ]);

    app(RemovePortalTheme::class)->handle('zz-uji', $this->admin);
    app()->call([new InstallThemeFromCatalogJob('zz-uji', 't1', $this->admin->id), 'handle']);

    Http::assertNothingSent();
    expect(themeInstalledTree())->toBe([])
        ->and(Setting::query()->find('themes.install.zz-uji'))->toBeNull();
});

it('AC-3: permission revoked after the page was opened stops install, rollback and remove at the action', function () {
    $package = signedThemePackage('zz-baru', '1.0.0');
    themeFakeCatalog([$package['entry']]);
    themeInstalledWrite('zz-lokal', [...themeBaseManifest(), 'version' => '1.0.0'], previous: json_encode([...themeBaseManifest(), 'slug' => 'zz-lokal', 'version' => '0.9.0']));
    Queue::fake();

    $user = kontenUser('super-admin');
    $this->actingAs($user);
    $component = Livewire::test(ThemeCatalog::class);
    $before = themeInstalledTree();

    $user->syncRoles([]);
    $user->unsetRelation('roles')->unsetRelation('permissions');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ([['install', 'zz-baru'], ['rollback', 'zz-lokal'], ['remove', 'zz-lokal']] as [$action, $slug]) {
        try {
            $component->callTableAction($action, $slug);
        } catch (Throwable) {
            // Penolakan lewat exception otorisasi dianggap benar.
        }
    }

    Queue::assertNothingPushed();
    expect(themeInstalledTree())->toBe($before)
        ->and(Setting::query()->find('themes.install.zz-baru'))->toBeNull()
        ->and(kontenActivities()->pluck('description')->all())->not->toContain('portal_theme_rolled_back', 'portal_theme_removed');
});

it('AC-3: switching the Konten module off after the page was opened stops the actions', function () {
    $package = signedThemePackage('zz-baru', '1.0.0');
    themeFakeCatalog([$package['entry']]);
    themeInstalledWrite('zz-lokal', [...themeBaseManifest(), 'version' => '1.0.0']);
    Queue::fake();

    $this->actingAs($this->admin);
    $component = Livewire::test(ThemeCatalog::class);
    Setting::set('modules.konten.enabled', false);
    $before = themeInstalledTree();

    foreach ([['install', 'zz-baru'], ['remove', 'zz-lokal']] as [$action, $slug]) {
        try {
            $component->callTableAction($action, $slug);
        } catch (Throwable) {
            // Penolakan lewat exception otorisasi dianggap benar.
        }
    }

    Queue::assertNothingPushed();
    expect(themeInstalledTree())->toBe($before);
});

it('AC-3, AC-5: a forged record key in a Livewire table action neither queues a job nor touches files', function () {
    themeFakeCatalog([signedThemePackage('zz-baru', '1.0.0')['entry']]);
    $outside = dirname(themeInstalledSandbox()).'/victim';
    mkdir($outside, 0777, true);
    file_put_contents("{$outside}/theme.json", 'AMAN');
    Queue::fake();

    $this->actingAs($this->admin);
    $component = Livewire::test(ThemeCatalog::class);

    foreach (['../victim', 'zz-tidak-ada', "zz-baru\n"] as $key) {
        foreach (['install', 'rollback', 'remove'] as $action) {
            try {
                $component->callTableAction($action, $key);
            } catch (Throwable) {
                // Kunci tak dikenal boleh melempar.
            }
        }
    }

    Queue::assertNothingPushed();
    expect(file_get_contents("{$outside}/theme.json"))->toBe('AMAN')
        ->and(Setting::query()->where('key', 'like', 'themes.install.%')->count())->toBe(0);
});

it('AC-4: catalog text with HTML is escaped on the page', function () {
    $package = signedThemePackage('zz-baru', '1.0.0', entry: [
        'name' => '<img src=x onerror=alert(1)>',
        'description' => '<script>alert(2)</script>',
    ]);
    themeFakeCatalog([$package['entry']]);

    $this->actingAs($this->admin);

    Livewire::test(ThemeCatalog::class)
        ->assertDontSeeHtml('<img src=x onerror=alert(1)>')
        ->assertDontSeeHtml('<script>alert(2)</script>');
});

it('AC-6: a failed job never leaks a refused key id, the response body or a Sodium message into the status or log', function () {
    $good = signedThemePackage('zz-uji', '1.0.0');
    config(['pondok.theme_public_keys' => ['k1' => 'bukan-base64!!', 'k2' => '']]);
    Setting::set('themes.install.zz-uji', ['state' => 'queued', 'token' => 't1', 'message' => null, 'at' => now()->toIso8601String()]);
    themeFakeHttp([
        THEME_CATALOG_URL => Http::response(json_encode(['themes' => [$good['entry']]]), 200),
        $good['entry']['url'] => Http::response($good['bytes'], 200),
    ]);

    app()->call([new InstallThemeFromCatalogJob('zz-uji', 't1', $this->admin->id), 'handle']);

    $status = Setting::query()->find('themes.install.zz-uji')->value;

    expect($status['state'])->toBe('failed')
        ->and(strtolower($status['message']))->not->toContain('sodium')
        ->and($status['message'])->not->toContain('bukan-base64')
        ->and($status['message'])->not->toContain(base64_encode($this->keys['k1']['secret']))
        ->and(themeInstalledTree())->toBe([]);

    Http::assertSent(fn (Request $request): bool => true);
});
