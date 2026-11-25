<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->actingAs(User::factory()->create()->assignRole('super-admin'));
});

it('menampilkan tautan panduan sesuai halaman', function (string $path, string $slug) {
    $this->get($path)->assertOk()->assertSee('https://pondok.org/panduan/'.$slug.'/', false);
})->with([
    'peran' => ['/admin/shield/roles', 'membuat-peran'],
    'pengguna' => ['/admin/users', 'menambah-pengguna'],
    'modul' => ['/admin/settings/modules', 'modul'],
]);

it('menaruh indeks panduan di menu pengguna', function () {
    $this->get('/admin')->assertSee('https://pondok.org/panduan/', false);
});

it('hanya memetakan slug panduan yang ada di pondok.org', function () {
    $known = ['membuat-peran', 'menambah-pengguna', 'mengelola-akun-pengguna', 'modul', 'input-prestasi'];

    expect(array_diff(config('pondok.guides.pages'), $known))->toBeEmpty();
});
