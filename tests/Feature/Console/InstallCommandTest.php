<?php

use App\Actions\Core\PublishPublicDirectory;
use App\Models\AcademicYear;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use App\Services\SystemProbe;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\Fixtures\FakeSystemProbe;

beforeEach(function () {
    $this->iso = isolateInstallation();
    $this->tmp = $this->iso->tmp;
    app()->instance(SystemProbe::class, new FakeSystemProbe);
    config(['app.url' => 'https://pondok.sch.id', 'pondok.super_admin' => ['name' => 'Super Admin', 'email' => null, 'password' => null]]);
});

afterEach(fn () => $this->iso->finish());

function installRun(array $args = []): array
{
    $exit = Artisan::call('pondok:install', $args + ['--no-interaction' => true]);

    return [$exit, Artisan::output()];
}

function adminOptions(array $overrides = []): array
{
    return $overrides + ['--admin-name' => 'Admin Pondok', '--admin-email' => 'admin@pondok.sch.id', '--admin-password' => 'rahasia123'];
}

function installCounts(): array
{
    return collect(['roles', 'permissions', 'settings', 'units', 'academic_years', 'document_types', 'admission_tracks', 'users'])
        ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
}

it('AC-11: stops when php is too old', function () {
    $probe = new FakeSystemProbe;
    $probe->version = '8.2.20';
    app()->instance(SystemProbe::class, $probe);

    [$exit, $output] = installRun(adminOptions());

    expect($exit)->toBe(1)->and($output)->toContain('8.2.20')->toContain('8.3')
        ->and(Role::count())->toBe(0)->and(Setting::count())->toBe(0);
});

it('AC-11: stops when an extension is missing', function () {
    $probe = new FakeSystemProbe;
    $probe->missingExtensions = ['intl'];
    app()->instance(SystemProbe::class, $probe);

    [$exit, $output] = installRun(adminOptions());

    expect($exit)->toBe(1)->and($output)->toContain('intl')->and(Role::count())->toBe(0);
});

it('AC-12: copies the production env example when .env is missing', function () {
    unlink($this->tmp.'/.env');

    [$exit, $output] = installRun(adminOptions());

    expect($exit)->toBe(1)
        ->and(file_get_contents($this->tmp.'/.env'))->toBe(file_get_contents(base_path('.env.production.example')))
        ->and($output)->toContain('DB_DATABASE')
        ->and(Role::count())->toBe(0);
});

it('AC-13: generates the app key', function () {
    file_put_contents($this->tmp.'/.env', "APP_KEY=\n");
    config(['app.key' => '']);

    [$exit] = installRun(adminOptions());

    expect($exit)->toBe(0)->and(file_get_contents($this->tmp.'/.env'))->toMatch('/^APP_KEY=base64:.+$/m');
});

it('AC-14: installs system data and creates the super admin', function () {
    [$exit, $output] = installRun(adminOptions());

    $user = User::query()->where('email', 'admin@pondok.sch.id')->first();

    expect($exit)->toBe(0)
        ->and(Role::pluck('name')->all())->toContain(...RolePermissionSeeder::ROLES)
        ->and(collect(SettingSeeder::MODULES)->every(fn ($m) => Setting::query()->whereKey("modules.{$m}.enabled")->exists()))->toBeTrue()
        ->and(Unit::pluck('code')->all())->toContain(...collect(UnitSeeder::UNITS)->pluck('code')->all())
        ->and(AcademicYear::query()->where('is_active', true)->exists())->toBeTrue()
        ->and($user->hasRole('super-admin'))->toBeTrue()
        ->and($user->is_active)->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('rahasia123', $user->password))->toBeTrue()
        ->and($output)->toContain('https://pondok.sch.id/admin')->toContain('schedule:run')->toContain(base_path('artisan'))
        ->toContain('pondok:doctor')->toContain('SUPER_ADMIN_PASSWORD')->not->toContain('pondok.install.');
});

it('AC-15: reads credentials from env and prefers options', function () {
    config(['pondok.super_admin' => ['name' => 'Env Admin', 'email' => 'env@pondok.sch.id', 'password' => 'rahasia123']]);

    [$exit] = installRun();

    expect($exit)->toBe(0)->and(User::where('email', 'env@pondok.sch.id')->exists())->toBeTrue();

    User::query()->delete();

    [$exit] = installRun(['--admin-email' => 'opsi@pondok.sch.id', '--admin-password' => 'rahasia456', '--admin-name' => 'Opsi']);

    expect($exit)->toBe(0)
        ->and(User::where('email', 'opsi@pondok.sch.id')->exists())->toBeTrue()
        ->and(User::where('email', 'env@pondok.sch.id')->exists())->toBeFalse();
});

it('AC-16: prompts for credentials', function () {
    $this->artisan('pondok:install')
        ->expectsQuestion(__('pondok.install.ask_admin_name'), 'Admin Prompt')
        ->expectsQuestion(__('pondok.install.ask_admin_email'), 'prompt@pondok.sch.id')
        ->expectsQuestion(__('pondok.install.ask_admin_password'), 'rahasia123')
        ->expectsQuestion(__('pondok.install.ask_admin_password_confirmation'), 'rahasia123')
        ->assertExitCode(0);

    expect(User::where('email', 'prompt@pondok.sch.id')->exists())->toBeTrue();
});

it('AC-16: a mismatched confirmation fails before touching the database', function () {
    $this->artisan('pondok:install')
        ->expectsQuestion(__('pondok.install.ask_admin_name'), 'Admin Prompt')
        ->expectsQuestion(__('pondok.install.ask_admin_email'), 'prompt@pondok.sch.id')
        ->expectsQuestion(__('pondok.install.ask_admin_password'), 'rahasia123')
        ->expectsQuestion(__('pondok.install.ask_admin_password_confirmation'), 'berbeda999')
        ->assertExitCode(1);

    expect(User::count())->toBe(0)->and(Role::count())->toBe(0);
});

it('AC-17: fails before touching the database without credentials', function () {
    [$exit, $output] = installRun();

    expect($exit)->toBe(1)->and($output)->toContain('SUPER_ADMIN_EMAIL')->and(Role::count())->toBe(0)->and(User::count())->toBe(0);
});

it('AC-17: rejects invalid credentials without seeding', function (string $email, string $password) {
    [$exit] = installRun(adminOptions(['--admin-email' => $email, '--admin-password' => $password]));

    expect($exit)->toBe(1)->and(User::count())->toBe(0)->and(Role::count())->toBe(0);
})->with([['bukan-email', 'rahasia123'], ['a@pondok.sch.id', '1234567']]);

it('AC-18: is idempotent and never overwrites changes', function () {
    installRun(adminOptions());
    $first = installCounts();

    Setting::set('modules.konten.enabled', true);
    $user = User::where('email', 'admin@pondok.sch.id')->first();
    $user->update(['password' => 'diubah12345']);

    [$exit] = installRun(adminOptions());

    expect($exit)->toBe(0)
        ->and(installCounts())->toBe($first)
        ->and(Setting::get('modules.konten.enabled'))->toBeTrue()
        ->and(Hash::check('diubah12345', $user->fresh()->password))->toBeTrue();

    [$exit, $output] = installRun();

    expect($exit)->toBe(0)->and($output)->not->toContain('pondok.install.')->and(installCounts())->toBe($first);
});

it('AC-19: promotes an existing user without changing the password', function () {
    $this->seed(RolePermissionSeeder::class);
    $user = User::factory()->create(['email' => 'guru@pondok.sch.id', 'password' => 'lamaaaaa1']);
    $user->assignRole('admin-sekolah');

    [$exit, $output] = installRun(adminOptions(['--admin-email' => 'guru@pondok.sch.id', '--admin-password' => 'baru12345']));

    expect($exit)->toBe(0)
        ->and($user->fresh()->hasRole('super-admin'))->toBeTrue()
        ->and($user->fresh()->hasRole('admin-sekolah'))->toBeTrue()
        ->and(Hash::check('lamaaaaa1', $user->fresh()->password))->toBeTrue()
        ->and($output)->not->toBe('')->not->toContain('pondok.install.');
});

it('AC-20: reports an unreachable database without a stack trace', function () {
    config(['database.default' => 'broken', 'database.connections.broken' => ['driver' => 'sqlite', 'database' => '/nonexistent-dir/x.sqlite', 'prefix' => '']]);

    [$exit, $output] = installRun(adminOptions());

    expect($exit)->toBe(1)->and($output)->toContain('DB_DATABASE')->not->toContain('PDOException')->not->toContain('#0 ');
});

it('AC-23: warns but succeeds when symlink is disabled', function () {
    $probe = new FakeSystemProbe;
    $probe->disabledFunctions = ['symlink'];
    app()->instance(SystemProbe::class, $probe);

    [$exit, $output] = installRun(adminOptions());

    expect($exit)->toBe(0)->and($output)->toContain('PUBLIC_STORAGE_ROOT='.$this->tmp.'/web/storage');
});

it('AC-46: publishes public html when configured', function () {
    config(['pondok.public_path' => $this->tmp.'/public_html']);
    mkdir($this->tmp.'/public_html');

    $this->mock(PublishPublicDirectory::class)->shouldReceive('handle')->once()
        ->withArgs(fn ($source, $target) => $target === $this->tmp.'/public_html')->andReturn(['copied' => 1]);

    [$exit] = installRun(adminOptions());

    expect($exit)->toBe(0);
});

it('AC-46: does not publish when public path is not configured', function () {
    $this->mock(PublishPublicDirectory::class)->shouldNotReceive('handle');

    [$exit] = installRun(adminOptions());

    expect($exit)->toBe(0);
});

it('remembers cpanel handler blocks from public/.htaccess during install', function () {
    file_put_contents($this->tmp.'/web/.htaccess', "# php -- BEGIN cPanel-generated handler, do not edit\nAddHandler application/x-httpd-ea-php83 .php\n# php -- END cPanel-generated handler, do not edit\n");

    installRun(adminOptions());

    expect(file_get_contents(storage_path('framework/pondok-cpanel-htaccess')))->toContain('ea-php83');
});
