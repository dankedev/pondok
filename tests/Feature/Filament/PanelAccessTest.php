<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SuperAdminSeeder;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Filament::setCurrentPanel('admin');
});

it('creates the super-admin account from the environment', function () {
    config([
        'pondok.super_admin.name' => 'Admin Pondok',
        'pondok.super_admin.email' => 'admin@pondok.test',
        'pondok.super_admin.password' => 'rahasia-sekali',
    ]);

    $this->seed(SuperAdminSeeder::class);

    $user = User::query()->where('email', 'admin@pondok.test')->sole();

    expect($user->name)->toBe('Admin Pondok')
        ->and($user->is_active)->toBeTrue()
        ->and($user->hasRole('super-admin'))->toBeTrue();
});

it('refuses to seed super-admin without credentials in the environment', function () {
    config(['pondok.super_admin.email' => null, 'pondok.super_admin.password' => null]);

    $this->seed(SuperAdminSeeder::class);
})->throws(RuntimeException::class, 'SUPER_ADMIN_EMAIL');

it('lets super-admin open the panel', function () {
    $user = User::factory()->create()->assignRole('super-admin');

    $this->actingAs($user)->get('/admin')->assertOk();
    $this->actingAs($user)->get('/admin/users')->assertOk();
});

it('lets an active super-admin log in and records the login time', function () {
    $user = User::factory()->create(['password' => 'kata-sandi-1'])->assignRole('super-admin');

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'kata-sandi-1'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->last_login_at)->not->toBeNull();
});

it('rejects login from an inactive user', function () {
    $user = User::factory()->inactive()->create(['password' => 'kata-sandi-1'])->assignRole('super-admin');

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'kata-sandi-1'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    $this->assertGuest();
});

it('returns 403 to an inactive user who still has a session', function () {
    $user = User::factory()->inactive()->create()->assignRole('super-admin');

    $this->actingAs($user)->get('/admin')->assertForbidden();
});

it('returns 403 to a role without view-dashboard', function () {
    $user = User::factory()->create()->assignRole('wali');

    $this->actingAs($user)->get('/admin')->assertForbidden();
});

it('redirects a guest to the login page', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('lets a limited role open the dashboard but not resources outside its permissions', function () {
    $user = User::factory()->create()->assignRole('bendahara');

    $this->actingAs($user)->get('/admin')->assertOk();
    $this->actingAs($user)->get('/admin/users')->assertForbidden();
    $this->actingAs($user)->get('/admin/staff')->assertForbidden();
});

it('lets admin-sekolah manage staff', function () {
    $user = User::factory()->create()->assignRole('admin-sekolah');

    $this->actingAs($user)->get('/admin/staff')->assertOk();
    $this->actingAs($user)->get('/admin/staff/create')->assertOk();
    $this->actingAs($user)->get('/admin/users')->assertForbidden();
});
