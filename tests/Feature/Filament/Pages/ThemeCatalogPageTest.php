<?php

use App\Actions\Konten\FetchThemeCatalog;
use App\Filament\Pages\Site\ThemeCatalog;
use App\Jobs\Konten\InstallThemeFromCatalogJob;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
| F103 AC-3 dan AC-4. Kontrak halaman `App\Filament\Pages\Site\ThemeCatalog` (grup Situs, `getModule()` = 'konten', `canAccess` = modul aktif +
| `manage:themes`). Katalog dimuat sekali di `mount` (`FetchThemeCatalog`) dan ulang lewat aksi header `refreshCatalog`. Tabel Filament
| berbasis `records()` dengan kunci record = slug: katalog digabung tema terpasang yang tak ada di katalog (tema resmi bawaan tidak ikut).
| Kolom `status` bernilai: `not_installed | installed | update_available | incompatible | queued | running | failed`.
| Aksi baris: `install` (tampil bila `not_installed|update_available`, nonaktif saat `queued|running`), `rollback` (tampil bila terpasang dan
| ada `previous.json`), `remove` (tampil bila terpasang). Teks: `pondok.theme_catalog.{empty,cache_note,status.*}`; galat katalog = pesan
| `FetchThemeCatalog::handle()['error']`.
*/

beforeEach(function () {
    kontenBoot();
    themeSandbox();
    themeInstalledSandbox();
    themeTestKeys();
});

/**
 * Katalog: baru, sama, naik, lawas (schema 99), core baru. Terpasang: sama (1.0.0), naik (1.0.0 + previous), lokal (tak ada di katalog).
 */
function tcpScenario(): void
{
    $entry = fn (string $slug, string $version, string $name, array $manifest = [], array $overrides = []): array => signedThemePackage($slug, $version, $manifest, ['name' => $name, ...$overrides])['entry'];

    themeFakeCatalog([
        $entry('zz-baru', '1.0.0', 'Tema Baru Uji'),
        $entry('zz-sama', '1.0.0', 'Tema Sama Uji'),
        $entry('zz-naik', '2.0.0', 'Tema Naik Uji'),
        $entry('zz-lawas', '1.0.0', 'Tema Lawas Uji', overrides: ['schema_version' => 99]),
        $entry('zz-core', '1.0.0', 'Tema Core Uji', overrides: ['min_core' => '999.0.0']),
    ]);

    themeInstalledWrite('zz-sama', [...themeBaseManifest(), 'version' => '1.0.0', 'name' => 'Tema Sama Uji']);
    themeInstalledWrite('zz-naik', [...themeBaseManifest(), 'version' => '1.0.0', 'name' => 'Tema Naik Uji'], previous: json_encode([...themeBaseManifest(), 'slug' => 'zz-naik', 'version' => '0.9.0']));
    themeInstalledWrite('zz-lokal', [...themeBaseManifest(), 'version' => '3.1.0', 'name' => 'Tema Lokal Uji']);
}

function tcpPage(string $role = 'super-admin')
{
    test()->actingAs(kontenUser($role));

    return Livewire::test(ThemeCatalog::class);
}

it('AC-3: only the super-admin can open the page; every other role gets 403', function () {
    tcpScenario();

    foreach (['admin-sekolah', 'admin-konten', 'bendahara', 'admin-psb', 'kesantrian', 'musyrif', 'petugas-pos', 'wali'] as $role) {
        $this->actingAs(kontenUser($role))->get(ThemeCatalog::getUrl())->assertForbidden();
    }

    $this->actingAs(kontenUser('super-admin'))->get(ThemeCatalog::getUrl())->assertOk();
});

it('AC-3: a role without manage:themes cannot mount the component directly either', function () {
    tcpScenario();

    tcpPage('admin-sekolah')->assertForbidden();
});

it('AC-3: with the Konten module off the page is 403, absent from the menu, and installed themes stay on disk', function () {
    tcpScenario();
    Setting::set('modules.konten.enabled', false);
    $before = themeInstalledTree();

    $this->actingAs(kontenUser('super-admin'))->get(ThemeCatalog::getUrl())->assertForbidden();

    expect(ThemeCatalog::getModule())->toBe('konten')
        ->and(situsNavigationLabels(kontenUser('super-admin')))->not->toContain(ThemeCatalog::getNavigationLabel())
        ->and(themeInstalledTree())->toBe($before);

    Setting::set('modules.konten.enabled', true);

    expect(situsNavigationLabels(kontenUser('super-admin')))->toContain(ThemeCatalog::getNavigationLabel())
        ->and(situsNavigationLabels(kontenUser('admin-sekolah')))->not->toContain(ThemeCatalog::getNavigationLabel());
});

it('AC-4: shows the right status for a new, current, outdated, and incompatible theme and for one that is installed only', function () {
    tcpScenario();

    tcpPage()
        ->assertCanSeeTableRecords(['zz-baru', 'zz-sama', 'zz-naik', 'zz-lawas', 'zz-core', 'zz-lokal'])
        ->assertTableColumnStateSet('status', 'not_installed', 'zz-baru')
        ->assertTableColumnStateSet('status', 'installed', 'zz-sama')
        ->assertTableColumnStateSet('status', 'update_available', 'zz-naik')
        ->assertTableColumnStateSet('status', 'incompatible', 'zz-lawas')
        ->assertTableColumnStateSet('status', 'incompatible', 'zz-core')
        ->assertTableColumnStateSet('status', 'installed', 'zz-lokal')
        ->assertSee('Tema Baru Uji')
        ->assertSee('Tema Lokal Uji')
        ->assertSee('2.0.0')
        ->assertSee(__('pondok.theme_catalog.cache_note'));

    expectTranslatedKeys(array_map(
        fn (string $status): string => "pondok.theme_catalog.status.{$status}",
        ['not_installed', 'installed', 'update_available', 'incompatible', 'queued', 'running', 'failed'],
    ));
});

it('AC-4: offers install, rollback and remove only where they apply', function () {
    tcpScenario();

    tcpPage()
        ->assertTableActionVisible('install', 'zz-baru')
        ->assertTableActionVisible('install', 'zz-naik')
        ->assertTableActionHidden('install', 'zz-sama')
        ->assertTableActionHidden('install', 'zz-lawas')
        ->assertTableActionHidden('install', 'zz-core')
        ->assertTableActionHidden('install', 'zz-lokal')
        ->assertTableActionVisible('rollback', 'zz-naik')
        ->assertTableActionHidden('rollback', 'zz-sama')
        ->assertTableActionHidden('rollback', 'zz-baru')
        ->assertTableActionVisible('remove', 'zz-sama')
        ->assertTableActionVisible('remove', 'zz-lokal')
        ->assertTableActionHidden('remove', 'zz-baru');
});

it('AC-4: a catalog error still renders the page with the installed themes and the error message', function () {
    themeFakeHttp([THEME_CATALOG_URL => Http::response('SECRET-ISI-RESPONS', 500)]);
    themeInstalledWrite('zz-lokal', [...themeBaseManifest(), 'version' => '3.1.0', 'name' => 'Tema Lokal Uji']);
    $error = app(FetchThemeCatalog::class)->handle()['error'];

    tcpPage()
        ->assertOk()
        ->assertSee($error)
        ->assertDontSee('SECRET-ISI-RESPONS')
        ->assertCanSeeTableRecords(['zz-lokal'])
        ->assertTableColumnStateSet('status', 'installed', 'zz-lokal');

    expect($error)->toBeString()->not->toBe('');
});

it('AC-4: shows the empty state when the catalog has no themes and nothing is installed', function () {
    themeFakeCatalog([]);

    tcpPage()->assertOk()->assertSee(__('pondok.theme_catalog.empty'))->assertSee(__('pondok.theme_catalog.cache_note'));

    expect(__('pondok.theme_catalog.empty'))->toBe('Katalog belum memuat tema.');
});

it('AC-4: loads the catalog once on mount and again only when the refresh action is used', function () {
    themeFakeCatalog([signedThemePackage('zz-baru')['entry']]);

    $page = tcpPage();
    Http::assertSentCount(1);

    $page->call('$refresh')->call('$refresh');
    Http::assertSentCount(1);

    themeFakeCatalog([signedThemePackage('zz-baru')['entry'], signedThemePackage('zz-kedua', entry: ['name' => 'Tema Kedua Uji'])['entry']]);
    $page->callAction('refreshCatalog');

    $page->assertCanSeeTableRecords(['zz-baru', 'zz-kedua']);
    Http::assertSentCount(1);
});

it('AC-5: the install action asks for confirmation, queues one job, and shows the row as queued with the button disabled', function () {
    tcpScenario();
    Queue::fake();

    tcpPage()
        ->callTableAction('install', 'zz-baru')
        ->assertHasNoTableActionErrors()
        ->assertTableColumnStateSet('status', 'queued', 'zz-baru')
        ->assertTableActionDisabled('install', 'zz-baru');

    Queue::assertPushed(InstallThemeFromCatalogJob::class, 1);
    Queue::assertPushed(InstallThemeFromCatalogJob::class, fn (InstallThemeFromCatalogJob $job): bool => $job->slug === 'zz-baru');
    expect(Setting::get('themes.install.zz-baru')['state'])->toBe('queued');
});

it('AC-5: shows running and failed (with the message) from the status, and counts a stale queued or running as failed', function () {
    tcpScenario();
    $at = now()->toIso8601String();
    Setting::set('themes.install.zz-baru', ['state' => 'running', 'token' => 't', 'message' => null, 'at' => $at]);
    Setting::set('themes.install.zz-naik', ['state' => 'failed', 'token' => 't', 'message' => 'Tanda tangan tema tidak sah.', 'at' => $at]);
    Setting::set('themes.install.zz-lawas', ['state' => 'queued', 'token' => 't', 'message' => null, 'at' => now()->subMinutes(6)->toIso8601String()]);
    Setting::set('themes.install.zz-core', ['state' => 'running', 'token' => 't', 'message' => null, 'at' => now()->subMinutes(6)->toIso8601String()]);

    tcpPage()
        ->assertTableColumnStateSet('status', 'running', 'zz-baru')
        ->assertTableActionDisabled('install', 'zz-baru')
        ->assertTableColumnStateSet('status', 'failed', 'zz-naik')
        ->assertSee('Tanda tangan tema tidak sah.')
        ->assertTableColumnStateSet('status', 'failed', 'zz-lawas')
        ->assertTableColumnStateSet('status', 'failed', 'zz-core');
});

it('AC-11: the remove and rollback actions go through the actions and refresh the row', function () {
    tcpScenario();
    $installed = themeInstalledFile('zz-naik', 'previous.json');

    tcpPage()
        ->callTableAction('rollback', 'zz-naik')
        ->assertHasNoTableActionErrors()
        ->assertTableActionHidden('rollback', 'zz-naik')
        ->callTableAction('remove', 'zz-lokal')
        ->assertHasNoTableActionErrors();

    expect(themeInstalledFile('zz-naik'))->toBe($installed)
        ->and(is_dir(themeInstalledSandbox().'/zz-lokal'))->toBeFalse()
        ->and(kontenActivities()->pluck('description')->all())->toContain('portal_theme_rolled_back', 'portal_theme_removed');
});

it('AC-14: has Indonesian text for the page title, the empty state, the cache note and the install messages', function () {
    expectTranslatedKeys(['pondok.theme_catalog.title', 'pondok.theme_catalog.empty', 'pondok.theme_catalog.cache_note']);
});
