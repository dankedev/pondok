<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    $this->actingAs(User::factory()->create()->assignRole('super-admin'));
});

it('menampilkan tautan panduan sesuai halaman', function (string $path, string $slug) {
    $this->get($path)->assertOk()->assertSee('https://pondok.org/panduan/'.$slug.'/', false);
})->with([
    'peran' => ['/admin/shield/roles', 'membuat-peran'],
    'pengguna' => ['/admin/users', 'menambah-pengguna'],
    'modul' => ['/admin/settings/modules', 'modul'],
    'santri' => ['/admin/students', 'menambah-santri'],
    'tagihan' => ['/admin/invoices', 'menerbitkan-tagihan'],
    'laporan keuangan' => ['/admin/finance-report', 'laporan-keuangan'],
    'tema portal' => ['/admin/site/theme', 'memilih-tema-portal'],
]);

it('menaruh indeks panduan di menu pengguna', function () {
    $this->get('/admin')->assertSee('https://pondok.org/panduan/', false);
});

it('memakai slug panduan polos tanpa anchor atau garis miring', function () {
    expect(collect(config('pondok.guides.pages'))->reject(fn (string $slug): bool => preg_match('/^[a-z0-9-]+$/', $slug) === 1))->toBeEmpty();
});
