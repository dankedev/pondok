<?php

/*
| F037 AC-35 dan AC-27 (halaman Menu) dipindah ke `NavigationPageTest` (F043). Halaman Menu dihapus;
| file ini hanya menjaga penghapusan itu.
*/

it('F043: the old Menu page and its url are gone', function () {
    kontenBoot(withPages: true);

    expect(class_exists('App\\Filament\\Clusters\\InstitutionProfile\\Pages\\Menu'))->toBeFalse();

    test()->actingAs(kontenUser('super-admin'))->get('/admin/institution-profile/menu')->assertNotFound();
});
