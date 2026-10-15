<?php

use App\Models\Setting;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
});

it('AC-27: lets kesantrian manage dormitories and residents', function () {
    $user = asramaUser('kesantrian');
    $dormitory = asramaDormitory();

    foreach (['/admin/dormitories', '/admin/dormitories/create', "/admin/dormitories/{$dormitory->id}/edit", '/admin/room-assignments'] as $url) {
        $this->actingAs($user)->get($url)->assertOk();
    }
});

it('AC-28: lets musyrif only view residents', function () {
    $user = asramaUser('musyrif');

    $this->actingAs($user)->get('/admin/room-assignments')->assertOk();
    $this->actingAs($user)->get('/admin/dormitories')->assertForbidden();
});

it('AC-29: forbids asrama resources to other roles', function (string $role) {
    $user = asramaUser($role);

    $this->actingAs($user)->get('/admin/dormitories')->assertForbidden();
    $this->actingAs($user)->get('/admin/room-assignments')->assertForbidden();
})->with(['bendahara', 'admin-konten', 'admin-psb', 'admin-sekolah', 'ustadz-tahfidz', 'petugas-pos']);

it('AC-32: returns 403 and hides the menu when the asrama module is disabled', function () {
    Setting::set('modules.asrama.enabled', false);
    $user = asramaUser('super-admin');

    $this->actingAs($user)->get('/admin/dormitories')->assertForbidden();
    $this->actingAs($user)->get('/admin/room-assignments')->assertForbidden();
    $this->actingAs($user)->get('/admin')->assertOk()->assertDontSee(__('pondok.navigation.asrama'));
});

it('AC-32: shows the asrama menu when the module is enabled', function () {
    $this->actingAs(asramaUser('super-admin'))->get('/admin')->assertSee(__('pondok.navigation.asrama'));
});

it('AC-32: enables the asrama module on a fresh install', function () {
    Setting::query()->delete();

    $this->seed(SettingSeeder::class);

    expect(Setting::moduleEnabled('asrama'))->toBeTrue();
});
