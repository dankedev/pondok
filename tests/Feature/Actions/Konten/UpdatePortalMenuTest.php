<?php

use App\Actions\Konten\UpdatePortalMenu;
use App\Actions\Konten\UpdatePortalNavigation;

/*
| F037 AC-26 dan AC-27 dipindah ke `UpdatePortalNavigationTest` (F043). Action lama dihapus; file ini tinggal
| menjaga penghapusan itu supaya tidak ada dua jalur simpan menu.
*/

it('F043: the old UpdatePortalMenu action is gone, replaced by UpdatePortalNavigation', function () {
    expect(class_exists(UpdatePortalMenu::class))->toBeFalse()
        ->and(class_exists(UpdatePortalNavigation::class))->toBeTrue();
});
