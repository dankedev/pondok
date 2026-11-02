<?php

use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\Models\Permission;

/*
| F103 AC-3. Izin halaman `manage:themes` (di `RolePermissionSeeder::PAGE_PERMISSIONS`) hanya dimiliki `super-admin` lewat `allPermissions`.
| Daftar persis izin per peran dijaga `RolePermissionSeederTest`; di sini hanya keberadaan izin dan pemegangnya.
*/

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

it('AC-3: defines manage:themes as a page permission and gives it to the super-admin only', function () {
    expect(RolePermissionSeeder::PAGE_PERMISSIONS)->toContain('manage:themes')
        ->and(Permission::query()->where('name', 'manage:themes')->exists())->toBeTrue()
        ->and(kontenUser('super-admin')->can('manage:themes'))->toBeTrue();

    foreach (['admin-sekolah', 'admin-konten', 'bendahara', 'admin-psb', 'kesantrian', 'wali'] as $role) {
        expect(kontenUser($role)->can('manage:themes'))->toBeFalse($role);
    }
});
