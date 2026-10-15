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
    'bendahara' => ['bendahara', [
        'view-dashboard', 'view-finance-metrics',
        'view-any:fee-type', 'view:fee-type', 'create:fee-type', 'update:fee-type',
        'view-any:fee-rate', 'view:fee-rate', 'create:fee-rate', 'update:fee-rate',
        'view-any:scholarship', 'view:scholarship', 'create:scholarship', 'update:scholarship',
        'view-any:fee-override', 'view:fee-override', 'create:fee-override', 'update:fee-override',
        'view-any:invoice', 'view:invoice', 'generate:invoice', 'cancel:invoice',
    ]],
    'admin-konten' => ['admin-konten', ['view-dashboard', 'view-content-metrics']],
    'admin-psb' => ['admin-psb', ['view-dashboard', 'view-psb-metrics']],
    'kesantrian' => ['kesantrian', [
        'view-dashboard', 'view-student-metrics',
        'view-any:student', 'view:student',
        'view-any:guardian', 'view:guardian',
        'view-any:dormitory', 'view:dormitory', 'create:dormitory', 'update:dormitory', 'delete:dormitory', 'delete-any:dormitory',
        'view-any:room', 'view:room', 'create:room', 'update:room', 'delete:room', 'delete-any:room',
        'view-any:room-assignment', 'view:room-assignment', 'create:room-assignment', 'update:room-assignment',
        'view-any:dormitory-supervisor', 'view:dormitory-supervisor', 'create:dormitory-supervisor', 'update:dormitory-supervisor',
    ]],
    'musyrif' => ['musyrif', ['view-dashboard', 'view-any:room-assignment', 'view:room-assignment']],
    'ustadz-tahfidz' => ['ustadz-tahfidz', ['view-dashboard']],
    'petugas-pos' => ['petugas-pos', ['view-dashboard']],
    'wali' => ['wali', []],
    'admin-sekolah' => ['admin-sekolah', [
        'view-dashboard', 'view-student-metrics', 'view-settings-metrics',
        'view-any:staff', 'view:staff', 'create:staff', 'update:staff', 'delete:staff', 'delete-any:staff',
        'view-any:unit', 'view:unit',
        'view-any:academic-year', 'view:academic-year',
        'view-any:cohort', 'view:cohort', 'create:cohort', 'update:cohort', 'delete:cohort', 'delete-any:cohort',
        'view-any:classroom', 'view:classroom', 'create:classroom', 'update:classroom', 'delete:classroom', 'delete-any:classroom',
        'view-any:student', 'view:student', 'create:student', 'update:student', 'delete:student', 'delete-any:student',
        'restore:student', 'restore-any:student',
        'view-any:guardian', 'view:guardian', 'create:guardian', 'update:guardian', 'delete:guardian', 'delete-any:guardian',
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

it('AC-52: creates the invoice resource and action permissions', function () {
    expect(Permission::query()->pluck('name')->all())->toContain(
        'view-any:invoice', 'view:invoice', 'create:invoice', 'update:invoice', 'delete:invoice', 'delete-any:invoice',
        'generate:invoice', 'cancel:invoice',
    );
});

it('AC-52: does not let bendahara create, update or delete invoices', function () {
    $user = User::factory()->create()->assignRole('bendahara');

    expect($user->can('generate:invoice'))->toBeTrue()
        ->and($user->can('cancel:invoice'))->toBeTrue()
        ->and($user->can('create:invoice'))->toBeFalse()
        ->and($user->can('update:invoice'))->toBeFalse()
        ->and($user->can('delete:invoice'))->toBeFalse()
        ->and($user->can('delete-any:invoice'))->toBeFalse();
});
