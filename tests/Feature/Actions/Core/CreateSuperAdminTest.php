<?php

use App\Actions\Core\CreateSuperAdmin;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

beforeEach(fn () => $this->seed(RolePermissionSeeder::class));

it('AC-19: creates a new super admin', function () {
    $result = app(CreateSuperAdmin::class)->handle('Admin', 'admin@pondok.sch.id', 'rahasia123');

    expect($result['created'])->toBeTrue()
        ->and($result['user']->hasRole('super-admin'))->toBeTrue()
        ->and($result['user']->is_active)->toBeTrue()
        ->and($result['user']->email_verified_at)->not->toBeNull()
        ->and(Hash::check('rahasia123', $result['user']->password))->toBeTrue();
});

it('AC-19: promotes an existing user without touching name or password', function () {
    $user = User::factory()->create(['email' => 'guru@pondok.sch.id', 'name' => 'Guru', 'password' => 'lamaaaaa1']);
    $user->assignRole('admin-sekolah');

    $result = app(CreateSuperAdmin::class)->handle('Baru', 'guru@pondok.sch.id', 'baru12345');

    $user->refresh();

    expect($result['created'])->toBeFalse()
        ->and($user->hasRole(['super-admin', 'admin-sekolah']))->toBeTrue()
        ->and($user->roles->pluck('name')->all())->toContain('super-admin', 'admin-sekolah')
        ->and($user->name)->toBe('Guru')
        ->and(Hash::check('lamaaaaa1', $user->password))->toBeTrue();
});

it('AC-17: validates name, email and password', function (string $name, string $email, string $password) {
    expect(fn () => app(CreateSuperAdmin::class)->handle($name, $email, $password))->toThrow(ValidationException::class);
    expect(User::count())->toBe(0);
})->with([
    'email salah' => ['Admin', 'bukan-email', 'rahasia123'],
    'password 7 karakter' => ['Admin', 'a@pondok.sch.id', '1234567'],
    'nama kosong' => ['', 'a@pondok.sch.id', 'rahasia123'],
]);

it('throws when the super-admin role has not been seeded', function () {
    Role::query()->delete();

    expect(fn () => app(CreateSuperAdmin::class)->handle('Admin', 'a@pondok.sch.id', 'rahasia123'))->toThrow(RuntimeException::class);
});
