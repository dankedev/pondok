<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

it('creates every role from CLAUDE.md and the PRD', function () {
    expect(Role::query()->pluck('name')->sort()->values()->all())->toBe([
        'admin-konten', 'admin-psb', 'admin-sekolah', 'bendahara', 'kesantrian',
        'musyrif', 'petugas-pos', 'super-admin', 'ustadz-tahfidz', 'wali',
    ]);
});

it('creates the dashboard permissions from PRD 3.10', function () {
    expect(Permission::query()->pluck('name')->all())->toContain(
        'view-dashboard',
        'view-content-metrics',
        'view-psb-metrics',
        'view-student-metrics',
        'view-finance-metrics',
        'view-settings-metrics',
    );
});

it('creates every permission that a generated policy checks', function () {
    $policyPermissions = collect(File::files(app_path('Policies')))
        ->flatMap(fn (SplFileInfo $file): array => preg_match_all("/->can\('([^']+)'\)/", $file->getContents(), $matches) ? $matches[1] : [])
        ->unique()
        ->values();

    expect($policyPermissions)->not->toBeEmpty()
        ->and(Permission::query()->pluck('name')->all())->toContain(...$policyPermissions->all());
});

it('does not duplicate roles or permissions when run again', function () {
    $roles = Role::query()->count();
    $permissions = Permission::query()->count();

    $this->seed(RolePermissionSeeder::class);

    expect(Role::query()->count())->toBe($roles)
        ->and(Permission::query()->count())->toBe($permissions);
});

it('grants each role exactly its permissions', function (string $role, array $expected) {
    expect(Role::findByName($role)->permissions->pluck('name')->sort()->values()->all())
        ->toBe(collect($expected)->sort()->values()->all());
})->with([
    'bendahara' => ['bendahara', ['view-dashboard', 'view-finance-metrics']],
    'admin-konten' => ['admin-konten', ['view-dashboard', 'view-content-metrics']],
    'admin-psb' => ['admin-psb', ['view-dashboard', 'view-psb-metrics']],
    'kesantrian' => ['kesantrian', ['view-dashboard', 'view-student-metrics']],
    'musyrif' => ['musyrif', ['view-dashboard']],
    'ustadz-tahfidz' => ['ustadz-tahfidz', ['view-dashboard']],
    'petugas-pos' => ['petugas-pos', ['view-dashboard']],
    'wali' => ['wali', []],
    'admin-sekolah' => ['admin-sekolah', [
        'view-dashboard', 'view-student-metrics', 'view-settings-metrics',
        'view-any:staff', 'view:staff', 'create:staff', 'update:staff', 'delete:staff', 'delete-any:staff',
        'view-any:unit', 'view:unit',
        'view-any:academic-year', 'view:academic-year',
    ]],
]);

it('lets super-admin pass any gate check', function () {
    $user = User::factory()->create()->assignRole('super-admin');

    expect($user->can('view-finance-metrics'))->toBeTrue()
        ->and($user->can('delete-any:user'))->toBeTrue()
        ->and($user->can('ability-that-does-not-exist'))->toBeTrue();
});

it('forbids a role from abilities outside its permissions', function () {
    $user = User::factory()->create()->assignRole('bendahara');

    expect($user->can('view-finance-metrics'))->toBeTrue()
        ->and($user->can('view-student-metrics'))->toBeFalse()
        ->and($user->can('view-any:user'))->toBeFalse();
});
