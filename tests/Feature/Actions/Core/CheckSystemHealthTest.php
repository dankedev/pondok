<?php

use App\Actions\Core\CheckSystemHealth;
use App\Actions\Core\RecordSchedulerHeartbeat;
use App\Services\SystemProbe;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\FakeSystemProbe;

const F019_KEYS = ['php_version', 'extensions', 'app_key', 'app_env', 'app_url', 'database', 'database_version', 'migrations', 'writable_paths', 'assets', 'public_storage', 'private_disk', 'persistent_storage', 'app_exposure', 'drivers', 'scheduler', 'queue', 'php_functions', 'php_limits', 'mail', 'version'];

beforeEach(function () {
    $this->iso = isolateInstallation();
    $this->tmp = $this->iso->tmp;
    $this->probe = new FakeSystemProbe;
    app()->instance(SystemProbe::class, $this->probe);

    app()['env'] = 'production';
    config([
        'app.debug' => false,
        'app.url' => 'https://pondok.sch.id',
        'mail.default' => 'smtp',
        'queue.default' => 'database',
        'cache.default' => 'database',
        'session.driver' => 'database',
    ]);
    symlink($this->tmp.'/storage/app/public', $this->tmp.'/web/storage');
    file_put_contents(storage_path('framework/pondok-scheduler-heartbeat'), (string) now()->subMinute()->timestamp);
});

afterEach(fn () => $this->iso->finish());

function health(string $key): array
{
    return collect(app(CheckSystemHealth::class)->handle())->firstWhere('key', $key);
}

function addJob(array $attributes): void
{
    DB::table('jobs')->insert($attributes + ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null]);
}

it('AC-29: passes a healthy server in the documented order', function () {
    $checks = app(CheckSystemHealth::class)->handle();

    expect(collect($checks)->pluck('key')->all())->toBe(F019_KEYS);

    foreach ($checks as $check) {
        expect($check['status'])->toBe($check['key'] === 'database_version' ? 'skipped' : 'ok', $check['key'].': '.$check['message']);
    }

    expect(app(CheckSystemHealth::class)->hasFailures($checks))->toBeFalse();
});

it('AC-30: fails on old php and missing extensions', function () {
    $this->probe->version = '8.2.20';
    $this->probe->missingExtensions = ['zip', 'intl'];

    expect(health('php_version')['status'])->toBe('failure')
        ->and(health('php_version')['message'])->toContain('8.2.20')->toContain('8.3')
        ->and(health('extensions')['status'])->toBe('failure')
        ->and(health('extensions')['message'])->toContain('intl, zip');
});

it('AC-31: requires every composer extension and the pdo driver', function () {
    $lock = json_decode(file_get_contents(base_path('composer.lock')), true);
    $required = collect($lock['packages'])->flatMap(fn ($p) => array_keys($p['require'] ?? []))
        ->filter(fn ($k) => str_starts_with($k, 'ext-'))->map(fn ($k) => substr($k, 4))->unique()->values()->all();

    expect(array_diff($required, CheckSystemHealth::REQUIRED_EXTENSIONS))->toBe([]);

    foreach (['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql', 'sqlite' => 'pdo_sqlite'] as $driver => $extension) {
        config(['database.connections.'.config('database.default').'.driver' => $driver]);
        $this->probe->missingExtensions = [$extension];

        expect(health('extensions')['status'])->toBe('failure', $driver)
            ->and(health('extensions')['message'])->toContain($extension);
    }
});

it('AC-32: validates app config and app url', function () {
    config(['app.key' => '']);
    expect(health('app_key')['status'])->toBe('failure');

    config(['app.debug' => true]);
    expect(health('app_env')['status'])->toBe('warning');

    app()['env'] = 'local';
    expect(health('app_env')['status'])->toBe('warning');

    config(['app.url' => 'http://localhost']);
    expect(health('app_url')['status'])->toBe('ok');

    app()['env'] = 'production';

    foreach (['not a url' => 'failure', 'http://localhost' => 'warning', 'https://pondok.test' => 'warning', 'http://pondok.sch.id' => 'warning', 'https://pondok.sch.id' => 'ok'] as $url => $status) {
        config(['app.url' => $url]);

        expect(health('app_url')['status'])->toBe($status, $url)
            ->and(health('app_url')['message'])->toContain($url);
    }
});

it('AC-33: detects pending migrations and an unreachable database', function () {
    DB::table('migrations')->orderByDesc('id')->limit(1)->delete();

    expect(health('migrations')['status'])->toBe('failure')->and(health('migrations')['message'])->toContain('1');
});

it('AC-33: a broken connection fails the database check and skips dependants', function () {
    config(['database.default' => 'broken', 'database.connections.broken' => ['driver' => 'sqlite', 'database' => '/nonexistent-dir/x.sqlite', 'prefix' => '']]);

    expect(health('database')['status'])->toBe('failure')
        ->and(health('migrations')['status'])->toBe('skipped')
        ->and(health('queue')['status'])->toBe('skipped')
        ->and(health('database_version')['status'])->toBe('skipped');
});

it('AC-34: compares database versions', function () {
    expect(CheckSystemHealth::databaseVersionSupported('mysql', '8.0.36'))->toBeTrue()
        ->and(CheckSystemHealth::databaseVersionSupported('mysql', '5.7.44-log'))->toBeFalse()
        ->and(CheckSystemHealth::databaseVersionSupported('mysql', '10.11.6-MariaDB-log'))->toBeTrue()
        ->and(CheckSystemHealth::databaseVersionSupported('mariadb', '10.4.32-MariaDB'))->toBeFalse()
        ->and(CheckSystemHealth::databaseVersionSupported('mysql', '10.6.0-MariaDB'))->toBeTrue();
});

it('AC-35: checks writable paths and built assets', function () {
    $this->probe->unwritable = [storage_path('framework/sessions')];

    expect(health('writable_paths')['status'])->toBe('failure')
        ->and(health('writable_paths')['message'])->toContain('storage/framework/sessions');

    unlink($this->tmp.'/web/build/manifest.json');
    expect(health('assets')['status'])->toBe('failure')->and(health('assets')['message'])->toContain('public/build');

    file_put_contents($this->tmp.'/web/build/manifest.json', '{}');
    unlink($this->tmp.'/web/js/filament/app.js');
    rmdir($this->tmp.'/web/js/filament');
    expect(health('assets')['status'])->toBe('failure')->and(health('assets')['message'])->toContain('js/filament');
});

it('AC-36: checks the storage disks', function () {
    unlink($this->tmp.'/web/storage');
    expect(health('public_storage')['status'])->toBe('warning')->and(health('public_storage')['message'])->toContain('PUBLIC_STORAGE_ROOT');

    config(['filesystems.disks.private.root' => $this->tmp.'/web/private']);
    expect(health('private_disk')['status'])->toBe('failure');
});

it('AC-36a: detects an app inside the web root', function () {
    expect(CheckSystemHealth::isInsideWebRoot('/home/u/public_html/pondok'))->toBeTrue()
        ->and(CheckSystemHealth::isInsideWebRoot('/home/u/www/pondok'))->toBeTrue()
        ->and(CheckSystemHealth::isInsideWebRoot('/home/u/pondok'))->toBeFalse()
        ->and(CheckSystemHealth::isInsideWebRoot('/home/public_htmlx/pondok'))->toBeFalse()
        ->and(health('app_exposure')['status'])->toBe('ok');
});

it('AC-37: checks drivers', function () {
    config(['queue.default' => 'redis']);
    expect(health('drivers')['status'])->toBe('warning')->and(health('drivers')['message'])->toContain('redis');

    config(['queue.default' => 'database', 'cache.default' => 'file', 'session.driver' => 'database']);
    expect(health('drivers')['status'])->toBe('ok');
});

it('AC-38: checks the scheduler heartbeat', function () {
    $path = storage_path('framework/pondok-scheduler-heartbeat');

    unlink($path);
    expect(health('scheduler')['status'])->toBe('warning')->and(health('scheduler')['message'])->toContain('schedule:run');

    file_put_contents($path, (string) now()->subMinutes(10)->timestamp);
    expect(health('scheduler')['status'])->toBe('warning');

    file_put_contents($path, (string) now()->subMinutes(2)->timestamp);
    expect(health('scheduler')['status'])->toBe('ok');

    $this->travelTo(now()->addMinutes(10));
    expect(health('scheduler')['status'])->toBe('warning');
});

it('AC-38: the heartbeat action writes the current timestamp', function () {
    $this->freezeTime();
    unlink(storage_path('framework/pondok-scheduler-heartbeat'));

    app(RecordSchedulerHeartbeat::class)->handle();

    expect((int) file_get_contents(storage_path('framework/pondok-scheduler-heartbeat')))->toBe(now()->timestamp);
});

it('AC-39: checks the queue', function () {
    $old = now()->subMinutes(15)->timestamp;

    expect(health('queue')['status'])->toBe('ok');

    addJob(['available_at' => now()->subMinute()->timestamp, 'created_at' => now()->subMinute()->timestamp]);
    expect(health('queue')['status'])->toBe('ok');

    DB::table('jobs')->delete();
    addJob(['available_at' => now()->addHour()->timestamp, 'created_at' => $old]);
    addJob(['available_at' => $old, 'created_at' => $old, 'reserved_at' => $old]);
    expect(health('queue')['status'])->toBe('ok');

    addJob(['available_at' => $old, 'created_at' => $old]);
    expect(health('queue')['status'])->toBe('warning');

    DB::table('jobs')->delete();

    foreach ([1, 2] as $i) {
        DB::table('failed_jobs')->insert(['uuid' => "uuid-{$i}", 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);
    }

    expect(health('queue')['status'])->toBe('warning')->and(health('queue')['message'])->toContain('2');
});

it('AC-40: checks php functions and limits', function () {
    $this->probe->disabledFunctions = ['proc_open'];
    expect(health('php_functions')['status'])->toBe('warning')->and(health('php_functions')['message'])->toContain('proc_open');

    $this->probe->disabledFunctions = ['symlink'];
    expect(health('php_functions')['message'])->toContain('symlink');

    $this->probe->ini['upload_max_filesize'] = '2M';
    expect(health('php_limits')['status'])->toBe('warning')->and(health('php_limits')['message'])->toContain('2M')->toContain('5M');

    $this->probe->ini = ['upload_max_filesize' => '8M', 'post_max_size' => '16M', 'memory_limit' => '-1'];
    expect(health('php_limits')['status'])->toBe('ok');

    $this->probe->ini['memory_limit'] = '64M';
    expect(health('php_limits')['status'])->toBe('warning');

    $this->probe->ini = ['upload_max_filesize' => '8M', 'post_max_size' => '4M', 'memory_limit' => '256M'];
    expect(health('php_limits')['status'])->toBe('warning');
});

it('AC-40: the real probe reads disable_functions', function () {
    $probe = new SystemProbe;

    expect($probe->functionEnabled('strlen'))->toBeTrue()
        ->and($probe->functionEnabled('fungsi_yang_tidak_ada_f019'))->toBeFalse()
        ->and($probe->phpVersion())->toBe(PHP_VERSION);
});

it('AC-41: checks mail and version', function () {
    config(['mail.default' => 'log']);
    expect(health('mail')['status'])->toBe('warning');

    expect(health('version')['status'])->toBe('ok')->and(health('version')['message'])->toContain('dev');
});

it('F099: warns when docker storage is not a persistent volume', function () {
    expect(health('persistent_storage')['status'])->toBe('ok');

    $this->probe->inContainer = true;
    expect(health('persistent_storage')['status'])->toBe('warning')
        ->and(health('persistent_storage')['message'])->toContain(storage_path())->toContain('hilang');

    $this->probe->storageMounted = true;
    expect(health('persistent_storage')['status'])->toBe('ok');

    $this->probe->storageMounted = false;
    config(['filesystems.disks.private.driver' => 's3']);
    expect(health('persistent_storage')['status'])->toBe('warning');

    config(['filesystems.disks.public.driver' => 's3']);
    expect(health('persistent_storage')['status'])->toBe('ok')
        ->and(health('public_storage')['status'])->toBe('ok')
        ->and(health('private_disk')['status'])->toBe('ok');
});

it('F099: the real probe compares devices of a folder and its parent', function () {
    $probe = new SystemProbe;

    expect($probe->isMountPoint(storage_path()))->toBeFalse()
        ->and($probe->isMountPoint(storage_path('folder-yang-tidak-ada-f099')))->toBeFalse();
});
