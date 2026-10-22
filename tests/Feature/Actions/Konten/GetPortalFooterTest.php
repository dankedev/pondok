<?php

use App\Actions\Konten\GetPortalFooter;
use App\Actions\Konten\GetSchoolProfile;
use App\Models\Setting;

/*
| F046 AC-6 dan AC-7 (sisi Action). Kontrak: `GetPortalFooter::handle(array $profile): array` (profil = keluaran
| `GetSchoolProfile`) mengembalikan `{widgets, compactLinks}`. `widgets`: hanya widget tampil (`visible`) yang
| tidak kosong, urutan tersimpan; tiap elemen punya `type` dan `heading` (?string), lalu `body` dan `show_logo`
| (text), atau `links` (links: list<{label, url, active}> hanya yang tersedia). `compactLinks`: `links` dari
| widget `links` tampil pertama yang punya minimal satu tautan tersedia, selain itu `[]`.
*/

beforeEach(function () {
    portalBoot();
    Setting::set('modules.psb.enabled', false);
    Setting::set('modules.dokumen.enabled', false);
});

/**
 * @return array{widgets: list<array<string, mixed>>, compactLinks: list<array<string, mixed>>}
 */
function ftrResolve(): array
{
    app()->forgetScopedInstances();

    return app(GetPortalFooter::class)->handle(app(GetSchoolProfile::class)->handle());
}

it('AC-6: returns only the shown, non-empty widgets in the stored order with their resolved links', function () {
    $draft = staticPage(['status' => 'draft']);
    profilSet(['school.social_links' => [['platform' => 'instagram', 'label' => null, 'url' => 'https://instagram.com/pondokcontoh']]]);
    ftrSet([
        ftr('text', ['heading' => 'Tentang', 'body' => '<p>Isi</p>', 'show_logo' => false]),
        ftr('contacts', ['heading' => 'Kosong karena tanpa data']),
        ftr('social', ['heading' => 'Tersembunyi'], visible: false),
        ftr('links', ['heading' => 'Semua hilang', 'items' => [navPage(99999), navPage($draft)]]),
        ftr('text', ['heading' => 'Kosong', 'body' => null, 'show_logo' => false]),
        ftr('links', ['heading' => 'Tautan', 'items' => [navItem('url', 'Berita', ['url' => '/berita']), navPage(99999), navItem('psb.status')]]),
        ftr('social', ['heading' => 'Ikuti']),
    ]);

    $widgets = ftrResolve()['widgets'];

    expect(array_column($widgets, 'type'))->toBe(['text', 'links', 'social'])
        ->and(array_column($widgets, 'heading'))->toBe(['Tentang', 'Tautan', 'Ikuti'])
        ->and($widgets[0]['body'])->toContain('Isi')
        ->and(array_column($widgets[1]['links'], 'label'))->toBe(['Berita'])
        ->and(array_column($widgets[1]['links'], 'url'))->toBe(['/berita']);
});

it('AC-7: the compact links come from the first shown links widget that has an available link', function () {
    ftrSet([
        ftr('links', ['items' => [navItem('url', 'Tersembunyi', ['url' => '/a'])]], visible: false),
        ftr('links', ['items' => [navPage(99999)]]),
        ftr('links', ['items' => [navPage(99999), navItem('url', 'Pertama', ['url' => '/berita'])]]),
        ftr('links', ['items' => [navItem('url', 'Kedua', ['url' => '/galeri'])]]),
    ]);

    expect(array_column(ftrResolve()['compactLinks'], 'label'))->toBe(['Pertama']);

    ftrSet([ftr('text', ['body' => '<p>Isi</p>', 'show_logo' => false])]);

    expect(ftrResolve()['compactLinks'])->toBe([]);

    Setting::query()->whereKey('portal.footer_widgets')->delete();

    expect(ftrResolve())->toBe(['widgets' => [], 'compactLinks' => []]);
});
