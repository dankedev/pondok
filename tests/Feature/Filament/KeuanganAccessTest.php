<?php

use App\Models\Setting;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
});

it('AC-28: lets bendahara manage fee types and rates', function () {
    $user = keuanganUser('bendahara');

    foreach (['/admin/fee-types', '/admin/fee-rates'] as $url) {
        $this->actingAs($user)->get($url)->assertOk();
    }
});

it('AC-28: forbids keuangan resources to other roles', function (string $role) {
    $user = keuanganUser($role);

    $this->actingAs($user)->get('/admin/fee-types')->assertForbidden();
    $this->actingAs($user)->get('/admin/fee-rates')->assertForbidden();
})->with(['admin-sekolah', 'kesantrian', 'musyrif', 'admin-konten', 'admin-psb', 'ustadz-tahfidz', 'petugas-pos']);

it('AC-29: returns 403 and hides the menu when the keuangan module is disabled', function () {
    Setting::set('modules.keuangan.enabled', false);
    $user = keuanganUser('super-admin');

    $this->actingAs($user)->get('/admin/fee-types')->assertForbidden();
    $this->actingAs($user)->get('/admin/fee-rates')->assertForbidden();
    $this->actingAs($user)->get('/admin')->assertOk()->assertDontSee(__('pondok.navigation.keuangan'));
});

it('AC-29: shows the keuangan menu when the module is enabled', function () {
    expect(__('pondok.navigation.keuangan'))->not->toBe('pondok.navigation.keuangan');

    $this->actingAs(keuanganUser('super-admin'))->get('/admin')->assertSee(__('pondok.navigation.keuangan'));
});

it('AC-29: enables the keuangan module on a fresh install', function () {
    Setting::query()->delete();

    $this->seed(SettingSeeder::class);

    expect(Setting::moduleEnabled('keuangan'))->toBeTrue()
        ->and(Setting::moduleEnabled('akademik'))->toBeTrue()
        ->and(Setting::moduleEnabled('asrama'))->toBeTrue();
});

it('AC-28: lets bendahara manage scholarships and overrides', function () {
    $user = keuanganUser('bendahara');

    foreach (['/admin/scholarships', '/admin/fee-overrides'] as $url) {
        $this->actingAs($user)->get($url)->assertOk();
    }
});

it('AC-28: forbids scholarship and override pages to other roles', function (string $role) {
    $user = keuanganUser($role);

    $this->actingAs($user)->get('/admin/scholarships')->assertForbidden();
    $this->actingAs($user)->get('/admin/fee-overrides')->assertForbidden();
})->with(['admin-sekolah', 'kesantrian', 'musyrif', 'admin-konten', 'admin-psb', 'ustadz-tahfidz', 'petugas-pos']);

it('AC-29: returns 403 for scholarship and override pages when the module is disabled', function () {
    Setting::set('modules.keuangan.enabled', false);
    $user = keuanganUser('super-admin');

    $this->actingAs($user)->get('/admin/scholarships')->assertForbidden();
    $this->actingAs($user)->get('/admin/fee-overrides')->assertForbidden();
    $this->actingAs($user)->get('/admin')->assertOk()
        ->assertDontSee(__('pondok.scholarship.plural'))
        ->assertDontSee(__('pondok.fee_override.plural'));
});

it('AC-29: shows the scholarship and override menu when the module is enabled', function () {
    expect(__('pondok.scholarship.plural'))->not->toBe('pondok.scholarship.plural')
        ->and(__('pondok.fee_override.plural'))->not->toBe('pondok.fee_override.plural');

    $this->actingAs(keuanganUser('bendahara'))->get('/admin')->assertOk()
        ->assertSee(__('pondok.scholarship.plural'))
        ->assertSee(__('pondok.fee_override.plural'));
});
