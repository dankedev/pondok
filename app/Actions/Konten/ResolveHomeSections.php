<?php

namespace App\Actions\Konten;

/**
 * Bagian beranda siap render (F102): tiap blok dari `GetPortalHomeData` dipasangkan dengan node tema
 * (komponen dari peta tetap `ThemeComponents::HOME`, `variant`/`style` dari tema aktif), `headingId` dan latar
 * selang-seling (`band`). Tanpa tema atau tanpa konfigurasi bagian, node memakai komponen bawaan inti (`split`).
 *
 * Latar selang-seling (menggantikan closure `$band()` lama) dihitung di sini sesuai urutan blok yang tampil:
 * `bg-gray-50` lalu `bg-white`. Hanya bagian yang memakainya yang menggeser urutan: pada tampilan bawaan blok
 * Teks + gambar, Keunggulan, Kutipan, Konten terbaru, Video, dan Ajakan `light` (tidak untuk Hero dan Ajakan
 * `navy`/`gold`); pada bagian bergaya hanya `tone: auto`.
 */
class ResolveHomeSections
{
    private const BANDS = ['bg-gray-50', 'bg-white'];

    private const DEFAULT_BAND_TYPES = ['text_image', 'features', 'quote', 'feed', 'video'];

    /**
     * @param  list<array<string, mixed>>  $blocks  keluaran `GetPortalHomeData::handle()['blocks']`
     * @param  ?array<string, mixed>  $theme  keluaran `ResolveActiveTheme`
     * @return list<array{node: array<string, mixed>, block: array<string, mixed>, headingId: string, band: ?string}>
     */
    public function handle(array $blocks, ?array $theme): array
    {
        $configured = $theme['home']['sections'] ?? [];
        $bandIndex = 0;
        $sections = [];

        foreach (array_values($blocks) as $index => $block) {
            $type = $block['type'];
            $section = $configured[$type] ?? [];
            $styled = isset($section['style']);

            $usesBand = $styled
                ? ($section['style']['tone'] ?? null) === 'auto'
                : in_array($type, self::DEFAULT_BAND_TYPES, true) || ($type === 'cta' && ! in_array($block['data']['variant'], ['navy', 'gold'], true));

            $sections[] = [
                'node' => ['component' => ThemeComponents::HOME[$type], 'variant' => $section['variant'] ?? 'split', ...($styled ? ['style' => $section['style']] : [])],
                'block' => $block,
                'headingId' => 'blok-'.($index + 1),
                'band' => $usesBand ? self::BANDS[$bandIndex++ % 2] : null,
            ];
        }

        return $sections;
    }
}
