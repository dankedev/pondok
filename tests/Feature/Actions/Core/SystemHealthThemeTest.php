<?php

use App\Actions\Core\CheckSystemHealth;
use App\Models\Setting;
use App\Services\SystemProbe;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\FakeSystemProbe;

/*
| F103 AC-12. Check doctor `theme_catalog` (urutan: sesudah `mail`, sebelum `version`): `ok` bila katalog terjangkau (`FetchThemeCatalog`
| tanpa error), `warning` bila tak terjangkau, `skipped` bila modul Konten mati (tanpa request). Tidak pernah `failure`, jadi exit code
| `pondok:doctor` tak berubah. `sodium` wajib di `REQUIRED_EXTENSIONS` dan `composer.json`.
*/

beforeEach(function () {
    $this->iso = isolateInstallation();
    $this->probe = new FakeSystemProbe;
    app()->instance(SystemProbe::class, $this->probe);
    app()['env'] = 'production';
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.debug' => false, 'app.url' => 'https://pondok.sch.id', 'mail.default' => 'smtp', 'queue.default' => 'database', 'cache.default' => 'database', 'session.driver' => 'database']);
    symlink($this->iso->tmp.'/storage/app/public', $this->iso->tmp.'/web/storage');
    file_put_contents(storage_path('framework/pondok-scheduler-heartbeat'), (string) now()->subMinute()->timestamp);
    Setting::set('modules.konten.enabled', true);
    themeTestKeys();
});

afterEach(fn () => $this->iso->finish());

function sh103(): array
{
    return collect(app(CheckSystemHealth::class)->handle())->firstWhere('key', 'theme_catalog');
}

it('AC-12: checks the theme catalog right after mail, before version', function () {
    $keys = CheckSystemHealth::CHECKS;

    expect($keys)->toContain('theme_catalog')
        ->and(array_search('theme_catalog', $keys, true))->toBe(array_search('mail', $keys, true) + 1)
        ->and(array_search('version', $keys, true))->toBe(array_search('theme_catalog', $keys, true) + 1);
});

it('AC-12: is ok when the catalog can be fetched', function () {
    themeFakeCatalog([signedThemePackage('zz-uji')['entry']]);

    expect(sh103())->toMatchArray(['key' => 'theme_catalog', 'status' => 'ok'])
        ->and(sh103()['message'])->toBeString()->not->toBe('')->not->toStartWith('pondok.');
});

it('AC-12: warns, but does not fail, when the catalog cannot be reached', function () {
    foreach ([
        fn () => themeFakeHttp([THEME_CATALOG_URL => Http::response('x', 500)]),
        fn () => themeFakeHttp([THEME_CATALOG_URL => fn () => throw new ConnectionException('timeout')]),
        fn () => themeFakeHttp([THEME_CATALOG_URL => Http::response('bukan json', 200)]),
    ] as $arrange) {
        $arrange();
        $checks = app(CheckSystemHealth::class)->handle();

        expect(sh103()['status'])->toBe('warning')
            ->and(app(CheckSystemHealth::class)->hasFailures($checks))->toBeFalse();
    }
});

it('AC-12: is skipped without any request when the Konten module is off', function () {
    Setting::set('modules.konten.enabled', false);
    themeFakeHttp(['*' => Http::response('{}', 200)]);

    expect(sh103()['status'])->toBe('skipped');

    Http::assertNothingSent();
});

it('AC-12: pondok:doctor lists the check with its label and keeps exit code 0 when the catalog is down', function () {
    themeFakeHttp([THEME_CATALOG_URL => Http::response('x', 500)]);

    $exit = Artisan::call('pondok:doctor');

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain(__('pondok.doctor.checks.theme_catalog'))->not->toContain('pondok.doctor.');

    expectTranslatedKeys(['pondok.doctor.checks.theme_catalog']);
});

it('AC-12: requires the sodium extension in the checker, composer.json and composer.lock', function () {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
    $lock = json_decode((string) file_get_contents(base_path('composer.lock')), true);

    expect(CheckSystemHealth::REQUIRED_EXTENSIONS)->toContain('sodium')
        ->and($composer['require'])->toHaveKey('ext-sodium')
        ->and($lock['platform'])->toHaveKey('ext-sodium');

    $this->probe->missingExtensions = ['sodium'];
    $extensions = collect(app(CheckSystemHealth::class)->handle())->firstWhere('key', 'extensions');

    expect($extensions['status'])->toBe('failure')->and($extensions['message'])->toContain('sodium');
});
