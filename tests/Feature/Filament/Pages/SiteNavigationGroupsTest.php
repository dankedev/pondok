<?php

use App\Filament\Pages\Settings\Contacts;
use App\Filament\Pages\Settings\Identity;
use App\Filament\Pages\Site\Footer;
use App\Filament\Pages\Site\Home;
use App\Filament\Pages\Site\Navigation;
use App\Filament\Resources\Albums\AlbumResource;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Posts\PostResource;
use App\Filament\Resources\StaticPages\StaticPageResource;
use App\Filament\Resources\Videos\VideoResource;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
| F044 AC-9 dan AC-10. Kontrak: cluster Profil lembaga dihapus. Halaman `App\Filament\Pages\Site\{Home,Navigation,Footer}`
| (slug `site/home|navigation|footer`, grup `pondok.navigation.situs` = "Situs") dan `App\Filament\Pages\Settings\{Identity,Contacts}`
| (slug `settings/identity|contacts`, grup "Pengaturan", label "Identitas" dan "Kontak & sosial"). Urutan grup Situs:
| Beranda, Navigasi, Footer, Halaman, Berita, Kategori, Galeri, Video. Kunci `pondok.navigation.konten` tidak dipakai lagi.
*/

const FIVE_PAGES = ['/admin/site/home', '/admin/site/navigation', '/admin/site/footer', '/admin/settings/identity', '/admin/settings/contacts'];

beforeEach(function () {
    $this->f = kontenBoot(withPages: true);
});

it('AC-9: groups the admin navigation into Situs in the planned order', function () {
    expect(situsNavigationLabels(kontenUser('super-admin')))->toBe([
        'Beranda', 'Navigasi', 'Footer',
        StaticPageResource::getNavigationLabel(), PostResource::getNavigationLabel(), CategoryResource::getNavigationLabel(),
        AlbumResource::getNavigationLabel(), VideoResource::getNavigationLabel(),
    ]);
});

it('AC-9: lists the Situs group above Pengaturan in the sidebar', function () {
    $this->actingAs(kontenUser('super-admin'));
    Filament::setCurrentPanel('admin');

    $groups = collect(Filament::getNavigation())->map(fn ($group): ?string => $group->getLabel())->values();

    expect($groups->search(__('pondok.navigation.situs')))->toBeLessThan($groups->search(__('pondok.navigation.pengaturan')));
});

it('AC-9: puts Identitas and Kontak & sosial under Pengaturan and leaves no Profil lembaga item or cluster', function () {
    $this->actingAs(kontenUser('super-admin'));
    Filament::setCurrentPanel('admin');

    $groups = collect(Filament::getNavigation())->mapWithKeys(fn ($group): array => [(string) $group->getLabel() => collect($group->getItems())->map(fn ($item): string => (string) $item->getLabel())->all()]);
    $all = $groups->flatten()->all();

    expect($groups[__('pondok.navigation.pengaturan')])->toContain('Identitas', 'Kontak & sosial')
        ->and($groups->keys()->all())->not->toContain('Konten', 'Profil lembaga')
        ->and($all)->not->toContain('Profil lembaga', 'Kontak & lokasi')
        ->and(array_search('Beranda', $all, true))->not->toBeFalse()
        ->and(Contacts::getNavigationLabel())->toBe('Kontak & sosial')
        ->and(Identity::getNavigationLabel())->toBe('Identitas')
        ->and(class_exists('App\\Filament\\Clusters\\InstitutionProfile\\InstitutionProfileCluster'))->toBeFalse();
});

it('AC-10: lets the profile roles open the five pages and see them in the menu', function (string $role) {
    $user = kontenUser($role);

    foreach (FIVE_PAGES as $url) {
        $this->actingAs($user)->get($url)->assertOk();
    }

    $text = kontenNavigationText($user);

    expect($text)->toContain('Situs')->toContain('Identitas')->toContain('Kontak & sosial')->toContain('Navigasi')->toContain('Footer');
})->with(['super-admin', 'admin-sekolah', 'admin-konten']);

it('AC-10: forbids the five pages to the other roles and hides them from the menu', function (string $role) {
    $user = kontenUser($role);

    foreach (FIVE_PAGES as $url) {
        $this->actingAs($user)->get($url)->assertForbidden();
    }

    foreach ([Home::class, Navigation::class, Footer::class, Identity::class, Contacts::class] as $class) {
        Livewire::test($class)->assertForbidden();
        expect($class::canAccess())->toBeFalse();
    }

    if ($role !== 'wali') {
        expect(kontenNavigationText($user))->not->toContain('Kontak & sosial')->not->toContain('Situs')
            ->and(situsNavigationLabels($user))->toBe([]);
    }
})->with(['bendahara', 'admin-psb', 'kesantrian', 'musyrif', 'ustadz-tahfidz', 'petugas-pos', 'wali']);

it('AC-10: keeps the content resource matrix of the Situs resources unchanged', function (string $role, bool $allowed) {
    $user = kontenUser($role);

    foreach (['/admin/posts', '/admin/albums', '/admin/videos', '/admin/static-pages', '/admin/categories'] as $url) {
        $this->actingAs($user)->get($url)->assertStatus($allowed ? 200 : 403);
    }
})->with([
    ['super-admin', true], ['admin-konten', true],
    ['admin-sekolah', false], ['bendahara', false], ['admin-psb', false], ['kesantrian', false], ['musyrif', false], ['ustadz-tahfidz', false], ['petugas-pos', false],
]);
