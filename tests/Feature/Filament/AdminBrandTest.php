<?php

use App\Models\Setting;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
});

it('shows the logo, product name, and institution name in the admin header', function () {
    Setting::set('school.name', 'Pesantren Contoh Nusantara');

    $this->actingAs(keuanganUser('super-admin'))->get('/admin')
        ->assertOk()
        ->assertSee(__('pondok.brand.product'))
        ->assertSee('Pesantren Contoh Nusantara')
        ->assertSee('logo.svg', false);
});

it('shows only the logo on the login page', function () {
    Setting::set('school.name', 'Pesantren Contoh Nusantara');

    $this->get('/admin/login')
        ->assertOk()
        ->assertSee('logo.svg', false)
        ->assertDontSee('Pesantren Contoh Nusantara')
        ->assertDontSee('>'.__('pondok.brand.product').'</div>', false);
});
