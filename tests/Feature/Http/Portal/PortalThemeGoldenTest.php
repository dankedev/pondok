<?php

use App\Models\Album;
use App\Models\Setting;

/*
| F102 AC-6 (golden). Keluaran beranda preset `default` dan `centered` direkam dari kode SEBELUM refaktor ke renderer tema,
| lalu dibandingkan dengan keluaran sesudahnya: HTML harus identik (setelah normalisasi spasi, host, dan token acak).
| Tiga skenario memuat ketujuh tipe blok (hero, text_image, features, quote, cta, video, feed), varian cta `light/navy/gold`
| (hanya `light` yang memakai latar selang-seling), hero dengan gambar atau album, badge PSB, feed `posts/videos/albums`, serta
| header, footer, dan layout. Fixture: `tests/Fixtures/portal-themes/golden/<tema>/<skenario>.html`.
|
| JANGAN direkam ulang setelah refaktor (itu menghilangkan gunanya). Rekam ulang hanya bila perilaku beranda memang diubah
| sengaja: `UPDATE_PORTAL_GOLDEN=1 php artisan test --compact tests/Feature/Http/Portal/PortalThemeGoldenTest.php`.
*/

beforeEach(function () {
    portalBoot();
});

const GOLDEN_DIR = __DIR__.'/../../../Fixtures/portal-themes/golden';

/**
 * HTML dinormalisasi: host aplikasi, token acak, dan spasi diseragamkan; satu tag per baris agar selisih mudah dibaca.
 */
function goldenNormalize(string $html): string
{
    $html = str_replace(rtrim(url('/'), '/'), 'http://app.test', $html);
    $html = preg_replace('#/build/[^"\']*#', '/build/ASET', (string) $html);
    $html = preg_replace('/(nonce|csrf-token|_token)="[^"]*"/', '$1="X"', (string) $html);
    $html = preg_replace('/\s+/', ' ', (string) $html);
    $html = preg_replace('/>\s+</', '><', (string) $html);

    return trim((string) preg_replace('/>(?=<)/', ">\n", (string) $html))."\n";
}

/**
 * Isi album dengan berkas bernama tetap (helper bawaan memakai UUID acak).
 */
function goldenAlbum(string $title, int $photos): Album
{
    $album = kontenAlbum(['title' => $title]);

    for ($i = 1; $i <= $photos; $i++) {
        kontenPhoto($album, ['path' => "albums/{$album->id}/foto-{$i}.jpg", 'sort_order' => $i, 'caption' => $i === 1 ? "Foto pertama {$title}" : null]);
    }

    return $album;
}

/**
 * @return array<string, Closure(): void> skenario => penyusun data (dipanggil setelah `portalBoot`)
 */
function goldenScenarios(): array
{
    return [
        'semua-blok' => function (): void {
            portalPsbOpen();
            portalPost(['title' => 'Wisuda Tahfidz 2026']);
            goldenAlbum('Album Ramadan', 2);
            $video = kontenVideo(['title' => 'Profil Pondok']);
            navSet([navItem('home', 'Beranda'), navItem('posts', 'Berita'), navItem('psb.info', 'Info PSB')], navItem('psb.register', 'Daftar'));
            blkSet([
                blk('hero', ['eyebrow' => 'Penerimaan santri baru', 'title' => 'Mencetak generasi Qur\'ani', 'text' => "Belajar sunnah\ndengan adab.", 'image' => 'portal/blocks/hero.jpg', 'image_alt' => 'Gedung utama', 'primary' => navItem('psb.register', 'Daftar sekarang'), 'secondary' => navItem('posts', 'Baca berita')]),
                blk('features', ['heading' => 'Keunggulan', 'items' => [['title' => 'Tahfidz', 'text' => 'Setoran harian'], ['title' => 'Adab', 'text' => 'Pembinaan asrama'], ['title' => 'Sains']]]),
                blk('feed', ['source' => 'albums', 'heading' => 'Galeri']),
                blk('text_image', ['heading' => 'Tentang kami', 'text' => '<p>Isi <strong>tentang</strong> kami.</p>', 'image' => 'portal/blocks/tentang.jpg', 'image_alt' => 'Foto tentang', 'button' => navItem('posts', 'Selengkapnya')]),
                blk('quote', ['text' => 'Ilmu sebelum amal.', 'source' => 'Al-Bukhari']),
                blk('video', ['heading' => 'Video profil', 'video_id' => $video->id]),
                blk('cta', ['eyebrow' => 'Ajakan', 'heading' => 'Bergabunglah', 'text' => 'Gelombang pertama dibuka.', 'variant' => 'light', 'primary' => navItem('psb.register', 'Daftar'), 'secondary' => navItem('posts', 'Berita')]),
                blk('cta', ['heading' => 'Ajakan navy', 'variant' => 'navy', 'primary' => navItem('posts', 'Berita')]),
                blk('feed', ['source' => 'posts']),
                blk('cta', ['heading' => 'Ajakan emas', 'text' => 'Teks ajakan emas.', 'variant' => 'gold', 'primary' => navItem('posts', 'Berita')]),
                blk('features', ['items' => [['title' => 'Tanpa judul seksi', 'text' => 'Deskripsi']]]),
                blk('quote', ['text' => 'Kutipan tersembunyi.'], visible: false),
                blk('text_image', []),
            ]);
        },
        'hero-album' => function (): void {
            $album = goldenAlbum('Album Hero', 3);
            kontenVideo(['title' => 'Video unggulan', 'is_featured' => true]);
            blkSet([
                blk('hero', ['title' => 'Hero dengan album', 'album_id' => $album->id, 'primary' => navItem('posts', 'Berita')]),
                blk('text_image', ['heading' => 'Hanya judul']),
                blk('feed', ['source' => 'videos', 'heading' => 'Video']),
                blk('feed', ['source' => 'albums']),
                blk('cta', ['heading' => 'Penutup', 'variant' => 'light']),
            ]);
        },
        'tanpa-hero' => function (): void {
            blkSet([
                blk('quote', ['text' => 'Kutipan tanpa hero.']),
                blk('features', ['heading' => 'Keunggulan', 'items' => [['title' => 'Satu', 'text' => 'Deskripsi satu']]]),
                blk('cta', ['heading' => 'Ajakan akhir', 'variant' => 'light', 'primary' => navItem('posts', 'Berita')]),
            ]);
        },
    ];
}

it('AC-6: renders the home page exactly as the recorded pre-migration golden', function (string $theme, string $scenario) {
    goldenScenarios()[$scenario]();
    Setting::set('theme.template', $theme === 'default' ? null : $theme);
    app()->forgetScopedInstances();

    $html = goldenNormalize(blkHome());
    $path = GOLDEN_DIR."/{$theme}/{$scenario}.html";

    if (getenv('UPDATE_PORTAL_GOLDEN') === '1') {
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $html);
    }

    expect(is_file($path))->toBeTrue("Golden {$theme}/{$scenario} belum direkam")
        ->and($html)->toBe((string) file_get_contents($path), "Beranda {$theme}/{$scenario} berbeda dari golden");
})->with(function () {
    foreach (['default', 'centered'] as $theme) {
        foreach (['semua-blok', 'hero-album', 'tanpa-hero'] as $scenario) {
            yield "{$theme}/{$scenario}" => [$theme, $scenario];
        }
    }
});
