<?php

use App\Enums\Module;
use App\Models\AcademicYear;
use App\Models\Category;
use App\Models\Setting;
use App\Models\StaticPage;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\InstallSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\SuperAdminSeeder;
use Database\Seeders\SystemSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function f019Counts(): array
{
    return collect(['roles', 'permissions', 'settings', 'units', 'academic_years', 'document_types', 'admission_tracks', 'users', 'posts'])
        ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all()
        + ['pages' => StaticPage::query()->count(), 'categories' => Category::query()->count()];
}

it('AC-21: install seeder seeds system data without users', function () {
    $this->seed(InstallSeeder::class);

    $counts = f019Counts();

    expect($counts['users'])->toBe(0)
        ->and(Role::pluck('name')->sort()->values()->all())->toBe(collect(RolePermissionSeeder::ROLES)->sort()->values()->all())
        ->and(Unit::pluck('code')->sort()->values()->all())->toBe(collect(UnitSeeder::UNITS)->pluck('code')->sort()->values()->all())
        ->and(collect(Module::cases())->every(fn (Module $m) => Setting::query()->whereKey("modules.{$m->value}.enabled")->exists()))->toBeTrue()
        ->and($counts['permissions'])->toBeGreaterThan(0)->and($counts['academic_years'])->toBeGreaterThan(0)
        ->and($counts['document_types'])->toBeGreaterThan(0)->and($counts['admission_tracks'])->toBeGreaterThan(0)
        ->and($counts['pages'])->toBeGreaterThan(0)->and($counts['categories'])->toBeGreaterThan(0)
        ->and(AcademicYear::query()->where('is_active', true)->exists())->toBeTrue();
});

it('AC-21: database seeder equals install seeder plus exactly one super admin', function () {
    $this->seed(InstallSeeder::class);
    $before = f019Counts();

    config(['pondok.super_admin' => ['name' => 'Admin', 'email' => 'admin@pondok.sch.id', 'password' => 'rahasia123']]);
    $this->seed(DatabaseSeeder::class);

    expect(f019Counts())->toEqual(array_merge($before, ['users' => 1]))
        ->and(User::role('super-admin')->count())->toBe(1);
});

it('AC-21: super admin seeder still throws without credentials', function () {
    $this->seed(RolePermissionSeeder::class);
    config(['pondok.super_admin' => ['name' => 'Admin', 'email' => null, 'password' => null]]);

    expect(fn () => $this->seed(SuperAdminSeeder::class))->toThrow(RuntimeException::class);
});

it('AC-21: system seeder restores permissions and settings without overwriting', function () {
    $this->seed(InstallSeeder::class);
    Permission::query()->where('name', 'view-dashboard')->delete();
    Setting::query()->whereKey('modules.akademik.enabled')->delete();
    Setting::set('modules.konten.enabled', false);

    $this->seed(SystemSeeder::class);

    expect(Permission::query()->where('name', 'view-dashboard')->exists())->toBeTrue()
        ->and(Setting::query()->whereKey('modules.akademik.enabled')->exists())->toBeTrue()
        ->and(Setting::query()->find('modules.konten.enabled')->value)->toBeFalse();
});

it('AC-2: setting seeder creates the nine enum modules enabled and no perizinan row', function () {
    $this->seed(SettingSeeder::class);

    foreach (Module::cases() as $module) {
        expect(Setting::query()->find("modules.{$module->value}.enabled")?->value)->toBeTrue("modul {$module->value}");
    }

    expect(Setting::query()->where('key', 'like', 'modules.%.enabled')->count())->toBe(9)
        ->and(Setting::query()->whereKey('modules.perizinan.enabled')->exists())->toBeFalse();
});

it('AC-2: seeding again keeps a module that was switched off', function () {
    $this->seed(SettingSeeder::class);
    Setting::set('modules.psb.enabled', false);

    $this->seed(SettingSeeder::class);

    expect(Module::Psb->isEnabled())->toBeFalse();
});
