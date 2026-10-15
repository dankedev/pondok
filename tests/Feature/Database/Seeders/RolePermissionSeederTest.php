<?php

use App\Models\Applicant;
use App\Models\User;
use App\Policies\ApplicantPolicy;
use App\Policies\DocumentPolicy;
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
        'view-any:payment', 'view:payment', 'create:payment', 'cancel:payment',
        'view:finance-report',
    ]],
    'admin-konten' => ['admin-konten', ['view-dashboard', 'view-content-metrics']],
    'admin-psb' => ['admin-psb', [
        'view-dashboard', 'view-psb-metrics',
        'view-any:admission-wave', 'view:admission-wave', 'create:admission-wave', 'update:admission-wave',
        'view-any:admission-track', 'view:admission-track', 'create:admission-track', 'update:admission-track',
        'view-any:admission-requirement', 'view:admission-requirement', 'create:admission-requirement',
        'update:admission-requirement', 'delete:admission-requirement', 'delete-any:admission-requirement',
        'view-any:applicant', 'view:applicant', 'delete:applicant',
        'verify-document:applicant', 'reset-token:applicant', 'generate-invoices:applicant',
        'decide:applicant',
    ]],
    'kesantrian' => ['kesantrian', [
        'view-dashboard', 'view-student-metrics',
        'view-any:student', 'view:student',
        'view-any:guardian', 'view:guardian',
        'view-any:dormitory', 'view:dormitory', 'create:dormitory', 'update:dormitory', 'delete:dormitory', 'delete-any:dormitory',
        'view-any:room', 'view:room', 'create:room', 'update:room', 'delete:room', 'delete-any:room',
        'view-any:room-assignment', 'view:room-assignment', 'create:room-assignment', 'update:room-assignment',
        'view-any:dormitory-supervisor', 'view:dormitory-supervisor', 'create:dormitory-supervisor', 'update:dormitory-supervisor',
        'view-any:document', 'view:document',
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
        'view-any:document-type', 'view:document-type', 'create:document-type', 'update:document-type', 'delete:document-type', 'delete-any:document-type',
        'view-any:document', 'view:document', 'create:document', 'verify:document', 'view-confidential:document',
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

it('AC-30: creates payment permissions and keeps bendahara from deleting payments', function () {
    expect(Permission::query()->pluck('name')->all())->toContain(
        'view-any:payment', 'view:payment', 'create:payment', 'update:payment', 'delete:payment', 'delete-any:payment',
        'cancel:payment',
    );

    $user = User::factory()->create()->assignRole('bendahara');

    expect($user->can('view-any:payment'))->toBeTrue()
        ->and($user->can('view:payment'))->toBeTrue()
        ->and($user->can('create:payment'))->toBeTrue()
        ->and($user->can('cancel:payment'))->toBeTrue()
        ->and($user->can('update:payment'))->toBeFalse()
        ->and($user->can('delete:payment'))->toBeFalse()
        ->and($user->can('delete-any:payment'))->toBeFalse();
});

it('AC-30: gives payment permissions to no other role except super-admin', function (string $role) {
    expect(Role::findByName($role)->permissions->pluck('name')->filter(fn (string $name): bool => str_ends_with($name, ':payment'))->all())->toBe([]);
})->with(['admin-konten', 'admin-psb', 'admin-sekolah', 'kesantrian', 'musyrif', 'ustadz-tahfidz', 'petugas-pos', 'wali']);

it('AC-23: creates the finance report permission for bendahara only', function () {
    expect(Permission::query()->pluck('name')->all())->toContain('view:finance-report');

    $bendahara = User::factory()->create()->assignRole('bendahara');
    $admin = User::factory()->create()->assignRole('admin-sekolah');

    expect($bendahara->can('view:finance-report'))->toBeTrue()
        ->and($admin->can('view:finance-report'))->toBeFalse();
});

it('AC-23: gives the finance report permission to no other role', function (string $role) {
    expect(Role::findByName($role)->permissions->pluck('name')->contains('view:finance-report'))->toBeFalse();
})->with(['admin-konten', 'admin-psb', 'admin-sekolah', 'kesantrian', 'musyrif', 'ustadz-tahfidz', 'petugas-pos', 'wali']);

it('AC-22: creates the psb master permissions', function () {
    $expected = collect(['admission-wave', 'admission-track', 'admission-requirement'])
        ->flatMap(fn (string $resource): array => collect(['view-any', 'view', 'create', 'update', 'delete', 'delete-any'])
            ->map(fn (string $action): string => "{$action}:{$resource}")
            ->all());

    expect($expected)->toHaveCount(18)
        ->and(Permission::query()->pluck('name')->all())->toContain(...$expected->all());
});

it('AC-22: does not let admin-psb delete waves or tracks', function () {
    $user = User::factory()->create()->assignRole('admin-psb');

    expect($user->can('create:admission-wave'))->toBeTrue()
        ->and($user->can('delete:admission-wave'))->toBeFalse()
        ->and($user->can('delete-any:admission-wave'))->toBeFalse()
        ->and($user->can('delete:admission-track'))->toBeFalse()
        ->and($user->can('delete-any:admission-track'))->toBeFalse()
        ->and($user->can('delete:admission-requirement'))->toBeTrue();
});

it('AC-22: gives psb master permissions to no other role', function (string $role) {
    expect(Role::findByName($role)->permissions->pluck('name')->filter(fn (string $name): bool => str_contains($name, ':admission-'))->all())->toBe([]);
})->with(['admin-konten', 'admin-sekolah', 'bendahara', 'kesantrian', 'musyrif', 'ustadz-tahfidz', 'petugas-pos', 'wali']);

it('AC-22: leaves document types to the dokumen module (F013 AC-7 supersedes the F009 no-permission rule)', function () {
    expect(Permission::query()->where('name', 'like', '%:document-type')->count())->toBe(6);
});

it('AC-7: creates document permissions', function () {
    $expected = ['view-any', 'view', 'create', 'update', 'delete', 'delete-any'];
    $types = collect($expected)->map(fn (string $action): string => "{$action}:document-type")->all();
    $documents = collect($expected)->map(fn (string $action): string => "{$action}:document")->push('verify:document', 'view-confidential:document')->all();

    expect(Permission::query()->where('name', 'like', '%:document-type')->pluck('name')->sort()->values()->all())->toBe(collect($types)->sort()->values()->all())
        ->and(Permission::query()->where('name', 'like', '%:document')->pluck('name')->sort()->values()->all())->toBe(collect($documents)->sort()->values()->all());
});

it('AC-7: keeps document update and delete from every role but super-admin', function (string $role) {
    $names = Role::findByName($role)->permissions->pluck('name');

    expect($names->intersect(['update:document', 'delete:document', 'delete-any:document'])->all())->toBe([]);
})->with(['bendahara', 'admin-konten', 'admin-psb', 'admin-sekolah', 'kesantrian', 'musyrif', 'ustadz-tahfidz', 'petugas-pos', 'wali']);

it('AC-7: gives document permissions to no other role', function (string $role) {
    $names = Role::findByName($role)->permissions->pluck('name')->filter(fn (string $name): bool => str_ends_with($name, ':document') || str_ends_with($name, ':document-type'));

    expect($names->all())->toBe([]);
})->with(['bendahara', 'admin-konten', 'admin-psb', 'musyrif', 'ustadz-tahfidz', 'petugas-pos', 'wali']);

it('AC-7: lets super-admin update and delete documents', function () {
    $user = User::factory()->create()->assignRole('super-admin');

    expect($user->can('update:document'))->toBeTrue()->and($user->can('delete-any:document'))->toBeTrue();
});

it('AC-7: backs the document abilities with the document policy', function () {
    $policy = new DocumentPolicy;

    foreach (['verify', 'download', 'viewConfidential', 'uploadType'] as $method) {
        expect(method_exists($policy, $method))->toBeTrue("DocumentPolicy::{$method}");
    }
});

it('AC-30: creates the eleven applicant permissions', function () {
    $expected = ['view-any:applicant', 'view:applicant', 'create:applicant', 'update:applicant', 'delete:applicant', 'delete-any:applicant',
        'verify-document:applicant', 'reset-token:applicant', 'generate-invoices:applicant', 'decide:applicant', 'reopen:applicant'];

    expect(Permission::query()->where('name', 'like', '%:applicant')->pluck('name')->sort()->values()->all())
        ->toBe(collect($expected)->sort()->values()->all());
});

it('AC-30: lets admin-psb verify, reset tokens and delete but not create or update applicants', function () {
    $user = User::factory()->create()->assignRole('admin-psb');

    expect($user->can('view-any:applicant'))->toBeTrue()
        ->and($user->can('verify-document:applicant'))->toBeTrue()
        ->and($user->can('reset-token:applicant'))->toBeTrue()
        ->and($user->can('generate-invoices:applicant'))->toBeTrue()
        ->and($user->can('delete:applicant'))->toBeTrue()
        ->and($user->can('create:applicant'))->toBeFalse()
        ->and($user->can('update:applicant'))->toBeFalse()
        ->and($user->can('delete-any:applicant'))->toBeFalse();
});

it('AC-30: gives applicant permissions to no other role', function (string $role) {
    expect(Role::findByName($role)->permissions->pluck('name')->filter(fn (string $name): bool => str_ends_with($name, ':applicant'))->all())->toBe([]);
})->with(['admin-konten', 'admin-sekolah', 'bendahara', 'kesantrian', 'musyrif', 'ustadz-tahfidz', 'petugas-pos', 'wali']);

it('AC-23: creates the decide and reopen permissions', function () {
    expect(Permission::query()->pluck('name')->all())->toContain('decide:applicant', 'reopen:applicant');
});

it('AC-23: gives decide to admin-psb and reopen to nobody but super-admin', function () {
    $admin = User::factory()->create()->assignRole('admin-psb');
    $super = User::factory()->create()->assignRole('super-admin');

    expect($admin->can('decide:applicant'))->toBeTrue()
        ->and($admin->can('reopen:applicant'))->toBeFalse()
        ->and($super->can('decide:applicant'))->toBeTrue()
        ->and($super->can('reopen:applicant'))->toBeTrue()
        ->and(Role::findByName('admin-psb')->permissions->pluck('name')->contains('reopen:applicant'))->toBeFalse();
});

it('AC-23: gives decide and reopen to no other role', function (string $role) {
    $names = Role::findByName($role)->permissions->pluck('name');

    expect($names->contains('decide:applicant'))->toBeFalse()->and($names->contains('reopen:applicant'))->toBeFalse();
})->with(['bendahara', 'admin-konten', 'admin-sekolah', 'kesantrian', 'musyrif', 'ustadz-tahfidz', 'petugas-pos', 'wali']);

it('AC-23: backs the decide and reopen abilities with the applicant policy', function () {
    $policy = new ApplicantPolicy;

    expect(method_exists($policy, 'decide'))->toBeTrue()->and(method_exists($policy, 'reopen'))->toBeTrue();

    $admin = User::factory()->create()->assignRole('admin-psb');
    $applicant = new Applicant;

    expect($policy->decide($admin, $applicant))->toBeTrue()->and($policy->reopen($admin, $applicant))->toBeFalse();
});
