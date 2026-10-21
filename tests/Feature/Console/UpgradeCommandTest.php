<?php

use App\Actions\Core\PublishPublicDirectory;
use App\Models\Setting;
use App\Services\SystemProbe;
use Database\Seeders\InstallSeeder;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Database\Events\NoPendingMigrations;
use Illuminate\Foundation\Events\MaintenanceModeEnabled;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Fixtures\FakeSystemProbe;

beforeEach(function () {
    $this->iso = isolateInstallation();
    $this->tmp = $this->iso->tmp;
    app()->instance(SystemProbe::class, new FakeSystemProbe);
    config(['app.url' => 'https://pondok.sch.id']);
    $this->seed(InstallSeeder::class);
    Permission::query()->where('name', 'view-dashboard')->delete();
    Setting::query()->whereKey('modules.akademik.enabled')->delete();
    Setting::set('modules.konten.enabled', true);
});

afterEach(fn () => $this->iso->finish());

function upgradeRun(array $args = ['--force' => true]): array
{
    $exit = Artisan::call('pondok:upgrade', $args + ['--no-interaction' => true]);

    return [$exit, Artisan::output()];
}

function seededAgain(): bool
{
    return Permission::query()->where('name', 'view-dashboard')->exists();
}

it('AC-42: requires a backup confirmation', function () {
    [$exit, $output] = upgradeRun([]);

    expect($exit)->toBe(1)->and($output)->toContain('--force')
        ->and(app()->isDownForMaintenance())->toBeFalse()->and(seededAgain())->toBeFalse();
});

it('AC-42: answering no to the backup question changes nothing', function () {
    $this->artisan('pondok:upgrade')->expectsConfirmation(__('pondok.upgrade.confirm_backup'), 'no')->assertExitCode(1);

    expect(seededAgain())->toBeFalse()->and(app()->isDownForMaintenance())->toBeFalse();
});

it('AC-43: upgrades and restores system data without overwriting settings', function () {
    [$exit, $output] = upgradeRun();

    expect($exit)->toBe(0)
        ->and(seededAgain())->toBeTrue()
        ->and(Setting::query()->whereKey('modules.akademik.enabled')->exists())->toBeTrue()
        ->and(Role::findByName('super-admin')->hasPermissionTo('view-dashboard'))->toBeTrue()
        ->and(Setting::get('modules.konten.enabled'))->toBeTrue()
        ->and(app()->isDownForMaintenance())->toBeFalse()
        ->and($output)->toContain('pondok:doctor')->not->toContain('pondok.upgrade.');
});

it('AC-44: stays in maintenance mode when it was already down', function () {
    Artisan::call('down');

    [$exit] = upgradeRun();

    expect($exit)->toBe(0)->and(app()->isDownForMaintenance())->toBeTrue();
});

it('AC-45: stays down when migrations fail', function () {
    Event::listen([MigrationsStarted::class, NoPendingMigrations::class], fn () => throw new RuntimeException('boom'));

    [$exit, $output] = upgradeRun();

    expect($exit)->toBe(1)->and(app()->isDownForMaintenance())->toBeTrue()
        ->and($output)->toContain('boom')->toContain('php artisan up')->and(seededAgain())->toBeFalse();
});

it('AC-46: republishes public html when configured and not otherwise', function () {
    config(['pondok.public_path' => $this->tmp.'/public_html']);
    mkdir($this->tmp.'/public_html');

    $this->mock(PublishPublicDirectory::class)->shouldReceive('handle')->once()
        ->withArgs(fn ($source, $target, $base, $force = false) => $target === $this->tmp.'/public_html' && $force === false)->andReturn(['copied' => 1]);

    [$exit] = upgradeRun();

    expect($exit)->toBe(0);
});

it('AC-46: does not publish without configuration', function () {
    $this->mock(PublishPublicDirectory::class)->shouldNotReceive('handle');

    [$exit] = upgradeRun();

    expect($exit)->toBe(0);
});

it('AC-47: checks requirements before going down', function () {
    $probe = new FakeSystemProbe;
    $probe->version = '8.2.20';
    app()->instance(SystemProbe::class, $probe);

    Event::fake([MaintenanceModeEnabled::class]);

    [$exit] = upgradeRun();

    expect($exit)->toBe(1)->and(app()->isDownForMaintenance())->toBeFalse();
    Event::assertNotDispatched(MaintenanceModeEnabled::class);
});

it('AC-47a: stays down when a later step fails and skips optimize:clear', function () {
    config(['pondok.public_path' => $this->tmp.'/public_html']);
    mkdir($this->tmp.'/public_html');
    file_put_contents($this->tmp.'/bootstrap/cache/config.php', '<?php return [];');

    $this->mock(PublishPublicDirectory::class)->shouldReceive('handle')->andThrow(new RuntimeException('seed gagal'));

    [$exit, $output] = upgradeRun();

    expect($exit)->toBe(1)->and(app()->isDownForMaintenance())->toBeTrue()
        ->and($output)->toContain('seed gagal')->toContain('pondok:upgrade --force')
        ->and($this->tmp.'/bootstrap/cache/config.php')->toBeFile();
});

it('AC-47b: restores the cpanel php handler into public/.htaccess', function () {
    $block = "# php -- BEGIN cPanel-generated handler, do not edit\n<IfModule mime_module>\n  AddHandler application/x-httpd-ea-php83 .php .php8 .phtml\n</IfModule>\n# php -- END cPanel-generated handler, do not edit";
    file_put_contents(storage_path('framework/pondok-cpanel-htaccess'), $block."\n");
    file_put_contents($this->tmp.'/web/.htaccess', "RewriteEngine On\nRewriteRule ^ index.php [L]\n");

    [$exit] = upgradeRun();

    expect($exit)->toBe(0)->and(substr_count(file_get_contents($this->tmp.'/web/.htaccess'), $block))->toBe(1);
});
