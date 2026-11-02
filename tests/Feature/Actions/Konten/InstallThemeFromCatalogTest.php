<?php

use App\Actions\Konten\InstallThemeFromCatalog;
use App\Jobs\Konten\InstallThemeFromCatalogJob;
use App\Models\Setting;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
| F103 AC-5 dan AC-6. Kontrak:
|  - `InstallThemeFromCatalog::handle(string $slug, User $actor): void`: `AuthorizationException` bila bukan pemegang `manage:themes` atau modul Konten
|    mati; `InvalidArgumentException` (`invalid_slug`) untuk slug tak sah (sebelum key apa pun dibentuk). Menulis `Setting` `themes.install.<slug>`
|    = `{state: queued, token, message: null, at: ISO-8601}` dan mendorong satu `InstallThemeFromCatalogJob`. Bila status `queued|running` masih
|    segar (<= 5 menit) tidak melakukan apa pun (tanpa exception, tanpa job kedua); `done|failed` atau macet > 5 menit boleh diulang (token baru).
|  - `InstallThemeFromCatalogJob(string $slug, string $token, int $userId)`, `tries = 1`, `timeout = 120`; `handle()` di-resolve container;
|    `failed(Throwable)` menandai `failed` (hanya bila token masih berlaku). Job mengambil ulang katalog dan entri menurut slug.
|    Status `failed.message` = teks Indonesia generik (tanpa isi respons mentah).
*/

const IT_URL = 'https://pondok.org/themes/zz-uji/1.0.0/theme.json';

beforeEach(function () {
    kontenBoot();
    themeSandbox();
    themeInstalledSandbox();
    themeTestKeys();
    $this->admin = kontenUser('super-admin');
});

function itStatus(string $slug = 'zz-uji'): ?array
{
    return Setting::query()->find("themes.install.{$slug}")?->value;
}

function itSetStatus(string $state, string $token = 'token-lama', ?string $at = null, string $slug = 'zz-uji'): void
{
    Setting::set("themes.install.{$slug}", ['state' => $state, 'token' => $token, 'message' => null, 'at' => $at ?? now()->toIso8601String()]);
}

function itClaim(string $slug = 'zz-uji', $actor = null): void
{
    app(InstallThemeFromCatalog::class)->handle($slug, $actor ?? test()->admin);
}

function itRun(string $token = 'token-1', string $slug = 'zz-uji'): void
{
    $job = new InstallThemeFromCatalogJob($slug, $token, test()->admin->id);

    app()->call([$job, 'handle']);
}

/**
 * Katalog dan berkas tema palsu; `$onThemeRequest` dipanggil saat berkas diunduh.
 *
 * @param  array<string, mixed>  $package
 */
function itFakeCatalog(array $package, ?Closure $onThemeRequest = null, ?string $themeBody = null): void
{
    themeFakeHttp([
        THEME_CATALOG_URL => Http::response(json_encode(['themes' => [$package['entry']]]), 200),
        $package['entry']['url'] => function (Request $request) use ($package, $onThemeRequest, $themeBody) {
            $onThemeRequest?->__invoke();

            return Http::response($themeBody ?? $package['bytes'], 200);
        },
    ]);
}

it('AC-5: claim writes status queued with a token and pushes exactly one job for the slug', function () {
    Queue::fake();

    itClaim();

    $status = itStatus();

    expect($status['state'])->toBe('queued')
        ->and($status['token'])->toBeString()->not->toBe('')
        ->and($status['message'])->toBeNull()
        ->and(Carbon\Carbon::parse($status['at'])->diffInSeconds(now(), true))->toBeLessThan(5);

    Queue::assertPushed(InstallThemeFromCatalogJob::class, 1);
    Queue::assertPushed(InstallThemeFromCatalogJob::class, fn (InstallThemeFromCatalogJob $job): bool => $job->slug === 'zz-uji' && $job->token === $status['token']);
});

it('AC-5: a double click while queued or running does not create a second job or change the token', function () {
    Queue::fake();

    itClaim();
    $token = itStatus()['token'];
    itClaim();

    itSetStatus('running', $token);
    itClaim();

    Queue::assertPushed(InstallThemeFromCatalogJob::class, 1);
    expect(itStatus()['token'])->toBe($token);
});

it('AC-5: queued or running for more than five minutes counts as failed and can be started again; done and failed can be repeated', function () {
    Queue::fake();

    foreach (['queued', 'running'] as $state) {
        itSetStatus($state, 'macet', now()->subMinutes(6)->toIso8601String());
        itClaim();

        expect(itStatus()['state'])->toBe('queued')
            ->and(itStatus()['token'])->not->toBe('macet', $state);
    }

    foreach (['done', 'failed'] as $state) {
        itSetStatus($state, "lama-{$state}");
        itClaim();

        expect(itStatus()['state'])->toBe('queued', $state)
            ->and(itStatus()['token'])->not->toBe("lama-{$state}");
    }

    Queue::assertPushed(InstallThemeFromCatalogJob::class, 4);
});

it('AC-5: a status that is still fresh just under five minutes keeps blocking', function () {
    Queue::fake();
    itSetStatus('queued', 'segar', now()->subMinutes(4)->subSeconds(30)->toIso8601String());

    itClaim();

    Queue::assertNothingPushed();
    expect(itStatus()['token'])->toBe('segar');
});

it('AC-3: only the holder of manage:themes can claim an install, and the Konten module must be on', function () {
    Queue::fake();

    foreach (['admin-sekolah', 'admin-konten', 'bendahara', 'admin-psb', 'wali'] as $role) {
        expect(fn () => itClaim('zz-uji', kontenUser($role)))->toThrow(AuthorizationException::class);
    }

    Setting::set('modules.konten.enabled', false);
    expect(fn () => itClaim())->toThrow(AuthorizationException::class);

    Queue::assertNothingPushed();
    expect(itStatus())->toBeNull();
});

it('AC-5: rejects a malformed or traversal slug before touching settings or the queue', function () {
    Queue::fake();

    foreach (['../x', 'zz/../x', '', 'ZZ', 'x', "zz-uji\n", str_repeat('a', 41), '../../etc'] as $slug) {
        themeAssertRejected(fn () => itClaim($slug), 'invalid_slug');
    }

    Queue::assertNothingPushed();
    expect(Setting::query()->where('key', 'like', 'themes.install.%')->count())->toBe(0);
});

it('AC-6: a successful job moves queued to running to done, installs the theme and logs it with the actor', function () {
    $package = signedThemePackage('zz-uji', '1.0.0');
    $seen = [];
    itSetStatus('queued', 'token-1');
    itFakeCatalog($package, function () use (&$seen) {
        $seen[] = itStatus()['state'];
    });

    itRun('token-1');

    $log = kontenActivities()->where('description', 'portal_theme_installed');

    expect($seen)->toBe(['running'])
        ->and(itStatus())->toMatchArray(['state' => 'done', 'token' => 'token-1'])
        ->and(themeInstalledFile('zz-uji'))->toBe($package['bytes'])
        ->and($log)->toHaveCount(1)
        ->and($log->first()->causer_id)->toBe($this->admin->id);
});

it('AC-6: any failure of download or verification ends as failed with a plain Indonesian message and installs nothing', function () {
    $good = signedThemePackage('zz-uji', '1.0.0');
    $tampered = str_replace('Tema dasar uji', 'Tema dibajak', $good['bytes']);
    $before = themeInstalledTree();

    $cases = [
        'theme download 500' => fn () => themeFakeHttp([
            THEME_CATALOG_URL => Http::response(json_encode(['themes' => [$good['entry']]]), 200),
            $good['entry']['url'] => Http::response('SECRET-ISI-RESPONS', 500),
        ]),
        'catalog down' => fn () => themeFakeHttp([THEME_CATALOG_URL => Http::response('SECRET-ISI-RESPONS', 500)]),
        'slug not in catalog' => fn () => themeFakeCatalog([signedThemePackage('zz-lain')['entry']]),
        'sha256 wrong' => fn () => itFakeCatalog(['entry' => [...$good['entry'], 'sha256' => str_repeat('0', 64)], 'bytes' => $good['bytes']]),
        'signature of other bytes' => fn () => itFakeCatalog($good, themeBody: $tampered),
        'download longer than entry size' => fn () => itFakeCatalog(['entry' => [...$good['entry'], 'size' => strlen($good['bytes']) - 5], 'bytes' => $good['bytes']]),
        'download shorter than entry size' => fn () => itFakeCatalog(['entry' => [...$good['entry'], 'size' => strlen($good['bytes']) + 5], 'bytes' => $good['bytes']]),
        'redirect to another host' => fn () => themeFakeHttp([
            THEME_CATALOG_URL => Http::response(json_encode(['themes' => [$good['entry']]]), 200),
            $good['entry']['url'] => Http::response('', 302, ['Location' => 'https://evil.example/theme.json']),
            'https://evil.example/theme.json' => Http::response($good['bytes'], 200),
        ]),
        'unsupported schema' => function () {
            $bad = signedThemePackage('zz-uji', '1.0.0', ['schema_version' => 99]);
            itFakeCatalog($bad);
        },
    ];

    foreach ($cases as $label => $arrange) {
        itSetStatus('queued', 'token-1');
        $arrange();

        itRun('token-1');

        expect(itStatus()['state'])->toBe('failed', $label)
            ->and(itStatus()['token'])->toBe('token-1', $label)
            ->and(itStatus()['message'])->toBeString()->not->toBe('', $label)
            ->and(itStatus()['message'])->not->toContain('SECRET-ISI-RESPONS', $label)
            ->and(itStatus()['message'])->not->toStartWith('pondok.', $label)
            ->and(themeInstalledTree())->toBe($before, $label);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'evil.example'));
    }

    expect(kontenActivities()->where('description', 'portal_theme_installed'))->toHaveCount(0);
});

it('AC-6: failed() marks the current job as failed without the raw error, and ignores a stale job', function () {
    $job = new InstallThemeFromCatalogJob('zz-uji', 'token-1', $this->admin->id);

    itSetStatus('running', 'token-1');
    $job->failed(new RuntimeException('ISI-RAHASIA timed out'));

    expect(itStatus()['state'])->toBe('failed')
        ->and(itStatus()['message'])->toBeString()->not->toBe('')->not->toContain('ISI-RAHASIA');

    itSetStatus('running', 'token-baru');
    $job->failed(new RuntimeException('timeout'));

    expect(itStatus())->toMatchArray(['state' => 'running', 'token' => 'token-baru']);
});

it('AC-6: the job gives up after one try within two minutes', function () {
    $job = new InstallThemeFromCatalogJob('zz-uji', 'token-1', $this->admin->id);

    expect($job->tries)->toBe(1)->and($job->timeout)->toBe(120);
});

it('AC-6: a job whose module was switched off does nothing but mark itself failed', function () {
    itSetStatus('queued', 'token-1');
    itFakeCatalog(signedThemePackage('zz-uji', '1.0.0'));
    Setting::set('modules.konten.enabled', false);

    itRun('token-1');

    Http::assertNothingSent();
    expect(itStatus()['state'])->toBe('failed')
        ->and(themeInstalledTree())->toBe([]);
});

it('AC-5: a job with a stale token does nothing at all', function () {
    itSetStatus('queued', 'token-baru');
    itFakeCatalog(signedThemePackage('zz-uji', '1.0.0'));

    itRun('token-basi');

    Http::assertNothingSent();
    expect(itStatus())->toMatchArray(['state' => 'queued', 'token' => 'token-baru'])
        ->and(themeInstalledTree())->toBe([]);

    Setting::query()->where('key', 'themes.install.zz-uji')->delete();

    itRun('token-tanpa-status');

    Http::assertNothingSent();
    expect(itStatus())->toBeNull()->and(themeInstalledTree())->toBe([]);
});
