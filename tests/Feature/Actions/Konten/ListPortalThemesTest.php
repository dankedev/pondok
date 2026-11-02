<?php

use App\Actions\Konten\ListPortalThemes;

/*
| F102 AC-6 dan AC-8 (daftar). Kontrak yang diasumsikan: `ListPortalThemes::handle(): list<array{slug: string, name: string,
| description: string, valid: bool, error: ?string}>` membaca hanya `resources/themes/*`/theme.json; nama dan deskripsi berasal
| dari manifest; tema yang gagal validasi tetap muncul dengan `valid = false` dan `error` berisi alasan (tanpa exception).
*/

/**
 * Entri daftar tanpa tema uji milik test lain yang mungkin berjalan paralel.
 *
 * @return array<string, array<string, mixed>> slug => entri
 */
function themeListed(): array
{
    return collect(app(ListPortalThemes::class)->handle())
        ->reject(fn (array $entry): bool => str_starts_with($entry['slug'], 'zz-uji-'))
        ->keyBy('slug')
        ->all();
}

it('AC-6: lists exactly the three official themes, valid, with name and description taken from the manifest', function () {
    $themes = themeListed();

    expect(array_keys($themes))->toEqualCanonicalizing(['default', 'centered', 'klasik']);

    foreach ($themes as $slug => $theme) {
        $manifest = themeOfficialManifest($slug);

        expect($theme['valid'])->toBeTrue($slug)
            ->and($theme['error'])->toBeNull($slug)
            ->and($theme['name'])->toBe($manifest['name'])
            ->and($theme['description'])->toBe($manifest['description']);
    }
});

it('AC-8: keeps a theme with a broken manifest in the list, disabled with a reason, without failing the others', function () {
    $klasik = themeOfficialManifest('klasik');
    $good = themeInstall($klasik);
    $broken = [
        'json rusak' => themeInstall('{"schema_version":'),
        'schema 99' => themeInstall([...$klasik, 'schema_version' => 99]),
        'kunci tak dikenal' => themeInstall([...$klasik, 'assets' => ['css' => ['x.css']]]),
        'min_core terlalu baru' => themeInstall([...$klasik, 'min_core' => '999.0.0']),
    ];

    $entries = collect(app(ListPortalThemes::class)->handle())->keyBy('slug');

    expect($entries[$good]['valid'])->toBeTrue();

    foreach ($broken as $label => $slug) {
        expect($entries->has($slug))->toBeTrue($label)
            ->and($entries[$slug]['valid'])->toBeFalse($label)
            ->and($entries[$slug]['error'])->toBeString()->not->toBe('', $label);
    }
});
