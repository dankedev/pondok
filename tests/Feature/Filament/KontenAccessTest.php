<?php

use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Models\Setting;
use Livewire\Livewire;

const KONTEN_URLS = ['/admin/posts', '/admin/posts/create', '/admin/albums', '/admin/videos', '/admin/static-pages', '/admin/categories'];

beforeEach(function () {
    $this->f = kontenBoot(withPages: true);
});

it('AC-6: lets admin-konten and super-admin open the content pages', function (string $role) {
    $user = kontenUser($role);

    foreach (KONTEN_URLS as $url) {
        $this->actingAs($user)->get($url)->assertOk();
    }

    expect(kontenNavigationText($user))->toContain(__('pondok.navigation.situs'));
})->with(['admin-konten', 'super-admin']);

it('AC-6/AC-10: forbids the content pages to other roles and hides the Situs group (F044: Konten became Situs)', function (string $role) {
    $user = kontenUser($role);

    foreach (KONTEN_URLS as $url) {
        $this->actingAs($user)->get($url)->assertForbidden();
    }

    expect(__('pondok.navigation.situs'))->toBe('Situs')
        ->and(kontenNavigationText($user))->not->toContain(__('pondok.navigation.situs'));
})->with(['bendahara', 'admin-psb', 'kesantrian', 'musyrif', 'ustadz-tahfidz', 'petugas-pos']);

it('AC-10: admin-sekolah sees only the profile pages in Situs, not the content resources', function () {
    $user = kontenUser('admin-sekolah');

    foreach (KONTEN_URLS as $url) {
        $this->actingAs($user)->get($url)->assertForbidden();
    }

    expect(situsNavigationLabels($user))->toBe(['Beranda', 'Navigasi', 'Footer']);
});

it('AC-6a: limits the school profile to admin-konten, admin-sekolah and super-admin', function (string $role, bool $allowed) {
    $user = kontenUser($role);
    $this->actingAs($user)->get('/admin/settings/identity')->assertStatus($allowed ? 200 : 403);
    $this->actingAs($user)->get('/admin/settings/contacts')->assertStatus($allowed ? 200 : 403);

    expect(str_contains(kontenNavigationText($user), 'Kontak & sosial'))->toBe($allowed)
        ->and(str_contains(kontenNavigationText($user), 'Profil lembaga'))->toBeFalse();
})->with([
    'admin-sekolah' => ['admin-sekolah', true],
    'super-admin' => ['super-admin', true],
    'admin-konten' => ['admin-konten', true],
    'bendahara' => ['bendahara', false],
    'admin-psb' => ['admin-psb', false],
    'kesantrian' => ['kesantrian', false],
    'musyrif' => ['musyrif', false],
    'ustadz-tahfidz' => ['ustadz-tahfidz', false],
    'petugas-pos' => ['petugas-pos', false],
]);

it('AC-6a: groups the school profile under Pengaturan', function () {
    expect(__('pondok.navigation.pengaturan'))->toBe('Pengaturan')
        ->and(kontenNavigationText(kontenUser('admin-sekolah')))->toContain('Pengaturan');
});

it('AC-7: hides konten when the module is disabled but keeps the school profile', function () {
    Setting::set('modules.konten.enabled', false);
    $user = kontenUser('super-admin');

    foreach (['/admin/posts', '/admin/albums', '/admin/videos', '/admin/static-pages', '/admin/categories'] as $url) {
        $this->actingAs($user)->get($url)->assertForbidden();
    }

    $this->actingAs($user)->get('/admin/settings/identity')->assertOk();
    $this->actingAs($user)->get('/admin/site/navigation')->assertOk();

    expect(situsNavigationLabels($user))->toBe(['Beranda', 'Navigasi', 'Footer'])
        ->and(kontenNavigationText($user))->toContain('Kontak & sosial');
});

it('AC-7: shows the konten group again when the module is enabled', function () {
    Setting::set('modules.konten.enabled', false);
    Setting::set('modules.konten.enabled', true);

    expect(situsNavigationLabels(kontenUser('admin-konten')))->toHaveCount(8);
});

it('AC-8: does not scope posts by unit', function () {
    $user = kontenUser('admin-konten', ['SD']);
    $sltp = kontenPost(['title' => 'Berita SLTP', 'unit_id' => $this->f->sltp->id]);
    $general = kontenPost(['title' => 'Berita umum', 'unit_id' => null]);
    $sd = kontenPost(['title' => 'Berita SD', 'unit_id' => $this->f->sd->id]);

    $this->actingAs($user);

    Livewire::test(ListPosts::class)->assertCanSeeTableRecords([$sltp, $general, $sd]);
});

it('AC-39: renders the content pages without raw translation keys', function () {
    $admin = kontenUser('super-admin');

    foreach ([...KONTEN_URLS, '/admin/settings/identity', '/admin/settings/contacts', '/admin/site/home', '/admin/site/navigation', '/admin/site/footer'] as $url) {
        $text = kontenPageText($admin, $url);

        preg_match('/\bpondok\.[a-z_]+\.[a-z_.]+/', $text, $match);

        expect($match[0] ?? '')->toBe('', "Kunci lang mentah di {$url}");
    }
});

it('AC-39: renders the content pages with data without raw translation keys', function () {
    $admin = kontenUser('super-admin');
    $album = kontenAlbum();
    kontenPhoto($album);
    kontenVideo();
    kontenPost(['status' => 'terbit', 'published_at' => '2026-10-14 08:00:00']);
    kontenPost(['status' => 'terbit', 'published_at' => '2026-10-20 08:00:00']);

    foreach (['/admin/posts', '/admin/albums', '/admin/videos', '/admin/categories', "/admin/albums/{$album->id}/edit", '/admin/static-pages'] as $url) {
        $text = kontenPageText($admin, $url);

        preg_match('/\bpondok\.[a-z_]+\.[a-z_.]+/', $text, $match);

        expect($match[0] ?? '')->toBe('', "Kunci lang mentah di {$url}");
    }
});
