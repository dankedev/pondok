<?php

use App\Actions\Core\CheckSystemHealth;
use App\Actions\Core\PublishPublicDirectory;
use App\Actions\Release\BuildReleaseManifest;
use App\Services\SystemProbe;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\FakeSystemProbe;

beforeEach(function () {
    $this->iso = isolateInstallation();
    $this->tmp = $this->iso->tmp;
    app()->instance(SystemProbe::class, new FakeSystemProbe);
    config(['app.url' => 'https://pondok.sch.id']);
});

afterEach(fn () => $this->iso->finish());

function f019RepoTree(string $dir): array
{
    if (! is_dir($dir)) {
        return [];
    }

    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        $files[$f->getPathname()] = filemtime($f->getPathname());
    }

    ksort($files);

    return $files;
}

it('VERIFY: install keeps an existing .env and APP_KEY byte for byte and never touches repo bootstrap/cache', function () {
    $env = "APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=\nDB_DATABASE=x\n# komentar\n";
    file_put_contents($this->tmp.'/.env', $env);
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    $before = f019RepoTree(base_path('bootstrap/cache'));

    $exit = Artisan::call('pondok:install', ['--admin-email' => 'a@pondok.sch.id', '--admin-password' => 'rahasia123', '--no-interaction' => true]);

    expect($exit)->toBe(0)
        ->and(file_get_contents($this->tmp.'/.env'))->toBe($env)
        ->and(f019RepoTree(base_path('bootstrap/cache')))->toBe($before);
});

it('VERIFY: failing database output never leaks the password or app key', function () {
    config([
        'database.default' => 'leaky',
        'database.connections.leaky' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'x', 'username' => 'u', 'password' => 'S3cr3tPassw0rd', 'charset' => 'utf8mb4'],
        'app.key' => 'base64:KUNCIRAHASIAKUNCIRAHASIAKUNCIRAHASIA123=',
    ]);

    $install = Artisan::call('pondok:install', ['--admin-email' => 'a@pondok.sch.id', '--admin-password' => 'rahasia123', '--no-interaction' => true]);
    $installOutput = Artisan::output();
    $doctor = Artisan::call('pondok:doctor');
    $doctorOutput = Artisan::output();

    expect($install)->toBe(1)->and($doctor)->toBe(1);

    foreach ([$installOutput, $doctorOutput] as $output) {
        expect($output)->not->toContain('S3cr3tPassw0rd')->not->toContain('KUNCIRAHASIA')->not->toContain('rahasia123')->not->toContain('#0 ');
    }
});

it('VERIFY: doctor survives a missing migrations table', function () {
    Schema::drop('migrations');

    $checks = collect(app(CheckSystemHealth::class)->handle());

    expect($checks->firstWhere('key', 'migrations')['status'])->toBeIn(['failure', 'skipped']);
});

it('VERIFY: publish-public never deletes through a symlinked owned directory', function () {
    $src = $this->tmp.'/src';
    $target = $this->tmp.'/public_html';
    $outside = $this->tmp.'/outside';
    releaseFixture($src, ['index.php', 'build/manifest.json']);
    releaseFixture($outside, ['penting.txt']);
    mkdir($target);
    symlink($outside, $target.'/build');

    try {
        app(PublishPublicDirectory::class)->handle($src, $target, $this->tmp.'/pondok');
    } catch (RuntimeException) {
    }

    expect($outside.'/penting.txt')->toBeFile();
});

it('VERIFY: publish-public rejects a dotted target that resolves to the app or its parent', function (string $suffix) {
    $src = $this->tmp.'/src';
    releaseFixture($src, ['index.php', 'build/manifest.json']);
    mkdir($this->tmp.'/pondok');
    mkdir($this->tmp.'/public_html');

    expect(fn () => app(PublishPublicDirectory::class)->handle($src, $this->tmp.'/public_html/'.$suffix, $this->tmp.'/pondok'))
        ->toThrow(RuntimeException::class);

    expect(file_exists($this->tmp.'/index.php') || file_exists($this->tmp.'/pondok/index.php'))->toBeFalse();
})->with(['../pondok', '..']);

it('VERIFY: publish-public does not write outside the target', function () {
    $src = $this->tmp.'/src';
    $target = $this->tmp.'/public_html';
    releaseFixture($src, ['index.php', 'build/manifest.json', 'robots.txt', 'a/b/c.txt']);
    mkdir($target);
    mkdir($this->tmp.'/pondok');
    $before = f019RepoTree($this->tmp.'/pondok');

    app(PublishPublicDirectory::class)->handle($src, $target, $this->tmp.'/pondok');

    expect(f019RepoTree($this->tmp.'/pondok'))->toBe($before)
        ->and(glob($this->tmp.'/*', GLOB_ONLYDIR))->toEqualCanonicalizing([$this->tmp.'/pondok', $target, $src, $this->tmp.'/storage', $this->tmp.'/web', $this->tmp.'/bootstrap']);
});

it('VERIFY: manifest excludes secrets and data in nested or unusual places', function () {
    $root = f019TempDirectory('pondok-verify-');
    $leaks = ['vendor/x/.env', 'public/.env.production', 'app/.env.backup', 'storage/framework/testing/x', 'storage/pail/x', '.phpunit.cache/x', 'storage/app/private/a/b/doc.pdf', 'storage/app/public/a/b/img.jpg', 'storage/logs/laravel-2026-10-16.log', 'public/hot', 'node_modules/.bin/vite', '.git/config', '.idea/x', 'tests/Pest.php'];
    releaseFixture($root, [...releaseRuntimePaths(), ...$leaks, 'app/.env.example', 'config/.env.production.example']);

    $manifest = app(BuildReleaseManifest::class)->handle($root);
    f019RemoveDirectory($root);

    expect(array_intersect($manifest, $leaks))->toBe([])
        ->and($manifest)->toContain('app/.env.example', 'config/.env.production.example');
});

it('VERIFY: git export rules do not drop files the package needs', function () {
    foreach (['.htaccess', '.env.production.example', 'docs/instalasi.md', 'public/.htaccess', 'artisan'] as $path) {
        $out = Process::path(base_path())->run(['git', 'check-attr', 'export-ignore', '--', $path])->output();

        expect($out)->not->toContain('export-ignore: set');
    }
});

it('VERIFY: every translation key used by the F019 code exists in lang/id', function () {
    $files = array_merge(glob(base_path('app/Console/Commands/*.php')), glob(base_path('app/Actions/Core/*.php')), glob(base_path('app/Actions/Release/*.php')));
    $missing = [];

    foreach ($files as $file) {
        preg_match_all("/__\(\s*'(pondok\.(?:install|doctor|upgrade|release|publish_public)\.[a-z0-9_.]+)'/", file_get_contents($file), $m);

        foreach (array_unique($m[1]) as $key) {
            if (__($key) === $key) {
                $missing[] = $key;
            }
        }
    }

    expect($missing)->toBe([]);
});

it('VERIFY: scheduler needs one cron and the queue worker stops when empty', function () {
    $commands = collect(app(Schedule::class)->events())->map(fn ($e) => (string) $e->command)->implode("\n");

    expect($commands)->toContain('queue:work --stop-when-empty')
        ->and(config('queue.default'))->not->toBe('redis')
        ->and(file_get_contents(base_path('.env.production.example')))->not->toContain('redis');
});
