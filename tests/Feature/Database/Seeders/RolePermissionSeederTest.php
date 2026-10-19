<?php

use App\Models\Applicant;
use App\Models\IssuedLetter;
use App\Models\Post;
use App\Models\User;
use App\Policies\ApplicantPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\IssuedLetterPolicy;
use App\Policies\PostPolicy;
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

// Daftar persis per peran ini juga menjaga larangan lintas peran (mis. izin pembayaran hanya bendahara):
// izin yang bocor ke peran lain langsung membuat test ini gagal, jadi tidak perlu test "tidak untuk peran lain".
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
        'view-any:invoice', 'view:invoice', 'generate:invoice', 'cancel:invoice', 'import:invoice',
        'view-any:payment', 'view:payment', 'create:payment', 'cancel:payment',
        'view:finance-report',
        'view:finance-numbering',
    ]],
    'admin-konten' => ['admin-konten', [
        'view-dashboard', 'view-content-metrics',
        'view-any:category', 'view:category', 'create:category', 'update:category', 'delete:category', 'delete-any:category',
        'view-any:post', 'view:post', 'create:post', 'update:post', 'delete:post', 'delete-any:post',
        'restore:post', 'restore-any:post',
        'view-any:album', 'view:album', 'create:album', 'update:album', 'delete:album', 'delete-any:album',
        'view-any:video', 'view:video', 'create:video', 'update:video', 'delete:video', 'delete-any:video',
        'view-any:static-page', 'view:static-page', 'create:static-page', 'update:static-page', 'delete:static-page',
        'publish:achievement',
        'view:school-profile',
    ]],
    'admin-psb' => ['admin-psb', [
        'view-dashboard', 'view-psb-metrics',
        'view-any:admission-wave', 'view:admission-wave', 'create:admission-wave', 'update:admission-wave',
        'view-any:admission-track', 'view:admission-track', 'create:admission-track', 'update:admission-track',
        'view-any:admission-requirement', 'view:admission-requirement', 'create:admission-requirement',
        'update:admission-requirement', 'delete:admission-requirement', 'delete-any:admission-requirement',
        'view-any:applicant', 'view:applicant', 'delete:applicant',
        'verify-document:applicant', 'reset-token:applicant', 'generate-invoices:applicant',
        'decide:applicant', 're-register:applicant',
    ]],
    'kesantrian' => ['kesantrian', [
        'view-dashboard', 'view-student-metrics',
        'view-any:student', 'view:student',
        'view-any:guardian', 'view:guardian',
        'view-any:dormitory', 'view:dormitory', 'create:dormitory', 'update:dormitory', 'delete:dormitory', 'delete-any:dormitory',
        'view-any:room', 'view:room', 'create:room', 'update:room', 'delete:room', 'delete-any:room',
        'view-any:room-assignment', 'view:room-assignment', 'create:room-assignment', 'update:room-assignment', 'import:room-assignment',
        'view-any:dormitory-supervisor', 'view:dormitory-supervisor', 'create:dormitory-supervisor', 'update:dormitory-supervisor',
        'view-any:document', 'view:document',
        'view-any:achievement', 'view:achievement', 'create:achievement', 'update:achievement', 'delete:achievement',
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
        'restore:student', 'restore-any:student', 'import:student',
        'view-any:guardian', 'view:guardian', 'create:guardian', 'update:guardian', 'delete:guardian', 'delete-any:guardian',
        'view-any:document-type', 'view:document-type', 'create:document-type', 'update:document-type', 'delete:document-type', 'delete-any:document-type',
        'view-any:document', 'view:document', 'create:document', 'verify:document', 'view-confidential:document',
        'view:school-profile',
        'view-any:issued-letter', 'view:issued-letter', 'create:issued-letter', 'cancel:issued-letter', 'configure-numbering:issued-letter',
        'view-any:achievement', 'view:achievement', 'verify:achievement',
        'view:student-numbering',
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
        'generate:invoice', 'cancel:invoice', 'import:invoice',
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

it('AC-23: creates the finance report permission for bendahara only', function () {
    expect(Permission::query()->pluck('name')->all())->toContain('view:finance-report');

    $bendahara = User::factory()->create()->assignRole('bendahara');
    $admin = User::factory()->create()->assignRole('admin-sekolah');

    expect($bendahara->can('view:finance-report'))->toBeTrue()
        ->and($admin->can('view:finance-report'))->toBeFalse();
});

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

it('AC-30: creates the twelve applicant permissions', function () {
    $expected = ['view-any:applicant', 'view:applicant', 'create:applicant', 'update:applicant', 'delete:applicant', 'delete-any:applicant',
        'verify-document:applicant', 'reset-token:applicant', 'generate-invoices:applicant', 'decide:applicant', 'reopen:applicant', 're-register:applicant'];

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

it('AC-23: backs the decide and reopen abilities with the applicant policy', function () {
    $policy = new ApplicantPolicy;

    expect(method_exists($policy, 'decide'))->toBeTrue()->and(method_exists($policy, 'reopen'))->toBeTrue();

    $admin = User::factory()->create()->assignRole('admin-psb');
    $applicant = new Applicant;

    expect($policy->decide($admin, $applicant))->toBeTrue()->and($policy->reopen($admin, $applicant))->toBeFalse();
});

it('AC-5: creates the content permissions with restore only for posts', function () {
    $names = Permission::query()->pluck('name');

    expect($names->all())->toContain('restore:post', 'restore-any:post', 'view:school-profile', 'update:static-page', 'delete-any:video')
        ->and($names->contains('restore:album'))->toBeFalse()
        ->and($names->contains('restore:static-page'))->toBeFalse();
});

it('AC-33: grants the school profile to admin-konten, admin-sekolah and super-admin without duplicates', function () {
    $this->seed(RolePermissionSeeder::class);

    foreach (['admin-konten', 'admin-sekolah', 'super-admin'] as $role) {
        expect(Role::findByName($role)->permissions->pluck('name')->filter(fn (string $name): bool => $name === 'view:school-profile')->count())->toBe(1);
    }

    expect(Permission::query()->where('name', 'view:school-profile')->count())->toBe(1);
});

it('AC-5: lets super-admin hold every content permission and does not duplicate them on re-seed', function () {
    $super = Role::findByName('super-admin')->permissions->pluck('name');
    $count = Permission::query()->count();

    expect($super->all())->toContain('view:school-profile', 'restore-any:post', 'view-any:static-page');

    $this->seed(RolePermissionSeeder::class);

    expect(Permission::query()->count())->toBe($count)
        // F037 menambah dua permission (create:static-page, delete:static-page): 33 + 2.
        ->and(Role::findByName('admin-konten')->permissions()->count())->toBe(35);
});

it('AC-5: backs the post restore abilities with the post policy', function () {
    $policy = new PostPolicy;

    foreach (['restore', 'restoreAny'] as $method) {
        expect(method_exists($policy, $method))->toBeTrue("PostPolicy::{$method}");
    }

    $admin = User::factory()->create()->assignRole('admin-konten');
    $other = User::factory()->create()->assignRole('bendahara');

    expect($policy->restore($admin, new Post))->toBeTrue()
        ->and($policy->restore($other, new Post))->toBeFalse();
});

it('AC-32: creates issued letter permissions', function () {
    $expected = collect(['view-any', 'view', 'create', 'update', 'delete', 'delete-any', 'cancel', 'configure-numbering'])
        ->map(fn (string $action): string => "{$action}:issued-letter")
        ->sort()->values()->all();

    expect(Permission::query()->where('name', 'like', '%:issued-letter')->pluck('name')->sort()->values()->all())->toBe($expected)
        ->and($expected)->toHaveCount(8);
});

it('AC-32: grants issued letter permissions to admin-sekolah and super-admin only', function () {
    $admin = User::factory()->create()->assignRole('admin-sekolah');
    $super = User::factory()->create()->assignRole('super-admin');

    foreach (['view-any', 'view', 'create', 'cancel', 'configure-numbering'] as $action) {
        expect($admin->can("{$action}:issued-letter"))->toBeTrue($action);
    }

    foreach (['update', 'delete', 'delete-any'] as $action) {
        expect($admin->can("{$action}:issued-letter"))->toBeFalse($action)
            ->and($super->can("{$action}:issued-letter"))->toBeTrue($action);
    }
});

it('AC-32: backs the cancel and numbering abilities with the issued letter policy', function () {
    $policy = new IssuedLetterPolicy;

    foreach (['cancel', 'configureNumbering'] as $method) {
        expect(method_exists($policy, $method))->toBeTrue("IssuedLetterPolicy::{$method}");
    }

    $admin = User::factory()->create()->assignRole('admin-sekolah');
    $other = User::factory()->create()->assignRole('bendahara');
    $issued = (new IssuedLetter)->forceFill(['status' => 'terbit']);
    $cancelled = (new IssuedLetter)->forceFill(['status' => 'batal']);

    expect($policy->cancel($admin, $issued))->toBeTrue()
        ->and($policy->cancel($admin, $cancelled))->toBeFalse()
        ->and($policy->cancel($other, $issued))->toBeFalse()
        ->and($policy->configureNumbering($admin))->toBeTrue()
        ->and($policy->configureNumbering($other))->toBeFalse();
});

it('AC-32: does not duplicate issued letter permissions when seeded again', function () {
    $count = Permission::query()->where('name', 'like', '%:issued-letter')->count();

    $this->seed(RolePermissionSeeder::class);

    expect(Permission::query()->where('name', 'like', '%:issued-letter')->count())->toBe($count)->toBe(8);
});

it('AC-7: creates achievement permissions', function () {
    expect(Permission::query()->pluck('name')->all())->toContain(
        'view-any:achievement', 'view:achievement', 'create:achievement', 'update:achievement', 'delete:achievement',
        'delete-any:achievement', 'verify:achievement', 'publish:achievement',
    );
});

it('AC-7: gives super-admin every achievement permission', function () {
    $user = User::factory()->create()->assignRole('super-admin');

    expect(Role::findByName('super-admin')->permissions->pluck('name'))->toContain('delete-any:achievement', 'verify:achievement', 'publish:achievement')
        ->and($user->can('delete-any:achievement'))->toBeTrue();
});

it('AC-31: creates the re-register permission', function () {
    expect(Permission::query()->pluck('name')->all())->toContain('re-register:applicant');
});

it('AC-31: gives re-register to admin-psb and super-admin only', function () {
    $admin = User::factory()->create()->assignRole('admin-psb');
    $super = User::factory()->create()->assignRole('super-admin');

    expect($admin->can('re-register:applicant'))->toBeTrue()
        ->and($super->can('re-register:applicant'))->toBeTrue()
        ->and(Role::findByName('admin-psb')->permissions->pluck('name')->contains('re-register:applicant'))->toBeTrue();
});

it('AC-31: lets the applicant policy check the re-register permission', function () {
    $applicant = Applicant::factory()->create();
    $allowed = User::factory()->create()->givePermissionTo('re-register:applicant');
    $denied = User::factory()->create()->givePermissionTo('decide:applicant');

    expect((new ApplicantPolicy)->reRegister($allowed, $applicant))->toBeTrue()
        ->and((new ApplicantPolicy)->reRegister($denied, $applicant))->toBeFalse();
});
