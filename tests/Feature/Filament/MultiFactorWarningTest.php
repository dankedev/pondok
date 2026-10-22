<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Filament::setCurrentPanel('admin');
});

it('AC-5: warns super-admin and bendahara without MFA and links to the profile', function (string $role) {
    expectTranslatedKeys(['pondok.user.security.mfa_warning_title']);

    $response = $this->actingAs(User::factory()->create()->assignRole($role))->get('/admin')->assertOk();

    $response->assertSee(__('pondok.user.security.mfa_warning_title'))
        ->assertSee(Filament::getProfileUrl(), false);
})->with(['super-admin', 'bendahara']);

it('AC-5: hides the warning once MFA is on', function () {
    expectTranslatedKeys(['pondok.user.security.mfa_warning_title']);
    $user = User::factory()->create()->assignRole('super-admin');
    mfaEnable($user);

    $this->actingAs($user)->get('/admin')->assertOk()->assertDontSee(__('pondok.user.security.mfa_warning_title'));
});

it('AC-5: never warns the other roles', function () {
    expectTranslatedKeys(['pondok.user.security.mfa_warning_title']);
    foreach (['admin-konten', 'admin-sekolah', 'admin-psb', 'kesantrian', 'musyrif', 'ustadz-tahfidz', 'petugas-pos'] as $role) {
        $this->actingAs(User::factory()->create()->assignRole($role))->get('/admin')->assertOk()
            ->assertDontSee(__('pondok.user.security.mfa_warning_title'));
    }
});
