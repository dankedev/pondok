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

    expect(kontenNavigationText($user))->toContain(__('pondok.navigation.konten'));
})->with(['admin-konten', 'super-admin']);

it('AC-6: forbids the content pages to other roles and hides the menu group', function (string $role) {
    $user = kontenUser($role);

    foreach (KONTEN_URLS as $url) {
        $this->actingAs($user)->get($url)->assertForbidden();
    }

    expect(__('pondok.navigation.konten'))->toBe('Konten')
        ->and(kontenNavigationText($user))->not->toContain(__('pondok.navigation.konten'));
})->with(['bendahara', 'admin-sekolah', 'admin-psb', 'kesantrian', 'musyrif', 'ustadz-tahfidz', 'petugas-pos']);

it('AC-6a: limits the school profile to admin-sekolah and super-admin', function (string $role, bool $allowed) {
    $user = kontenUser($role);
    $title = __('pondok.school_profile.title');

    expect($title)->not->toBe('pondok.school_profile.title');

    $this->actingAs($user)->get('/admin/school-profile')->assertStatus($allowed ? 200 : 403);

    expect(str_contains(kontenNavigationText($user), $title))->toBe($allowed);
})->with([
    'admin-sekolah' => ['admin-sekolah', true],
    'super-admin' => ['super-admin', true],
    'admin-konten' => ['admin-konten', false],
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

    $this->actingAs($user)->get('/admin/school-profile')->assertOk();

    expect(kontenNavigationText($user))->not->toContain(__('pondok.navigation.konten'))
        ->and(kontenNavigationText($user))->toContain(__('pondok.school_profile.title'));
});

it('AC-7: shows the konten group again when the module is enabled', function () {
    Setting::set('modules.konten.enabled', false);
    Setting::set('modules.konten.enabled', true);

    expect(kontenNavigationText(kontenUser('admin-konten')))->toContain(__('pondok.navigation.konten'));
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

    foreach ([...KONTEN_URLS, '/admin/school-profile'] as $url) {
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
