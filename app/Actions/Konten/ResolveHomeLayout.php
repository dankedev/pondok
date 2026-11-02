<?php

namespace App\Actions\Konten;

use Illuminate\Support\Arr;

/**
 * Susunan beranda siap render (F102): pohon `home.layout` tema aktif (atau `[{blocks, default}]` tanpa tema) dipasangkan
 * dengan data blok dari `GetPortalHomeData`. Berjalan sekali dalam urutan DOM dan memutuskan semua hal yang bergantung
 * pada isi, sehingga view hanya merender:
 *
 * - Pengikatan: node isi memakai blok aktif PERTAMA bertipe sama (penempatan berulang menampilkan konten sama).
 * - Node kosong: node isi tanpa blok, serta `container`/`columns` tanpa anak yang tampil, dibuang beserta pembungkusnya.
 *   `divider` hanya tampil bila ada konten yang tampil di kedua sisinya (di antara saudaranya).
 * - `headingId` unik per penempatan; hero pertama `h1`, selanjutnya `h2`; hero sesudah node bertajuk tetap `h2`
 *   dan `srH1` meminta `h1` sr-only di awal `<main>`. `banner` = pita nama lembaga preset `blocks` tanpa hero.
 * - Latar `tone: auto` selang-seling hanya atas node level atas yang tampil (`divider` tidak dihitung).
 * - Kelas (`class`) hanya lewat `ThemeStyleMap`; node bersarang tanpa `portal-gutter`, tone, dan padding bawaan bagian.
 *
 * Preset `blocks` (tema `default`/`centered`, atau tanpa tema) berisi `sections`: tiap blok aktif dengan latar selang-seling
 * lama (menggantikan closure `$band()`), dirender view legacy `themes/<preset>/blocks/*` dengan HTML tak berubah.
 */
class ResolveHomeLayout
{
    private const BANDS = ['bg-gray-50', 'bg-white'];

    private const DEFAULT_BAND_TYPES = ['text_image', 'features', 'quote', 'feed', 'video'];

    /** Node isi yang selalu merender judul (h2); `text_image` dan `feature_grid` hanya bila blok punya heading. */
    private const ALWAYS_TITLED = ['cta', 'video', 'feed_list'];

    /**
     * @param  list<array<string, mixed>>  $blocks  keluaran `GetPortalHomeData::handle()['blocks']`
     * @param  ?array<string, mixed>  $theme  keluaran `ResolveActiveTheme`
     * @return array{nodes: list<array<string, mixed>>, banner: bool, srH1: bool}
     */
    public function handle(array $blocks, ?array $theme): array
    {
        $layout = $theme['home']['layout'] ?? [['component' => 'blocks', 'variant' => 'default']];

        if (count($layout) === 1 && $layout[0]['component'] === 'blocks') {
            return [
                'nodes' => [[...$layout[0], 'sections' => $this->sections($blocks)]],
                'banner' => ! in_array('hero', array_column($blocks, 'type'), true),
                'srH1' => false,
            ];
        }

        $state = ['blocks' => [], 'seq' => 0, 'band' => 0, 'hero' => null, 'titled' => false];

        foreach ($blocks as $block) {
            $state['blocks'][$block['type']] ??= $block;
        }

        $nodes = $this->siblings($layout, true, false, $state);

        return ['nodes' => $nodes, 'banner' => false, 'srH1' => ($state['hero'] ?? 2) !== 1];
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array{component: string, block: array<string, mixed>, headingId: string, band: ?string}>
     */
    private function sections(array $blocks): array
    {
        $bandIndex = 0;
        $sections = [];

        foreach (array_values($blocks) as $index => $block) {
            $type = $block['type'];
            $usesBand = in_array($type, self::DEFAULT_BAND_TYPES, true) || ($type === 'cta' && ! in_array($block['data']['variant'], ['navy', 'gold'], true));

            $sections[] = [
                'component' => ThemeComponents::HOME[$type],
                'block' => $block,
                'headingId' => 'blok-'.($index + 1),
                'band' => $usesBand ? self::BANDS[$bandIndex++ % 2] : null,
            ];
        }

        return $sections;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, mixed>  $state
     * @return list<array<string, mixed>>
     */
    private function siblings(array $nodes, bool $top, bool $inverse, array &$state): array
    {
        $walked = [];

        foreach ($nodes as $node) {
            $walked[] = $node['component'] === 'divider' ? $node : $this->node($node, $top, $inverse, $state);
        }

        $shown = [];

        foreach ($walked as $index => $item) {
            if ($item === null) {
                continue;
            }

            if ($item['component'] === 'divider') {
                $before = $shown !== [] && end($shown)['component'] !== 'divider';
                $after = collect(array_slice($walked, $index + 1))->contains(fn (?array $next): bool => $next !== null && $next['component'] !== 'divider');

                if (! $before || ! $after) {
                    continue;
                }

                $item['class'] = ThemeStyleMap::classes(array_filter($item['style'] ?? [], fn (mixed $value): bool => $value !== 'auto'));
            }

            $shown[] = $item;
        }

        return $shown;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $state
     * @return ?array<string, mixed>
     */
    private function node(array $node, bool $top, bool $inverse, array &$state): ?array
    {
        $component = $node['component'];
        $style = $node['style'] ?? [];
        $tone = $style['tone'] ?? null;
        $inverse = match ($tone) {
            'dark', 'brand' => true,
            'light', 'muted', 'auto' => false,
            default => $inverse,
        };
        $extra = [];

        if (in_array($component, ['container', 'columns'], true)) {
            $children = $this->siblings($node['children'] ?? [], false, $inverse, $state);

            if ($children === []) {
                return null;
            }

            $extra = ['children' => $children];
            $style = $component === 'columns' ? Arr::except($style, ['columns']) : $style;

            if ($component === 'columns') {
                $extra['columnsClass'] = ThemeStyleMap::columns($node['style'] ?? [], count($children));
            }
        } else {
            $block = $state['blocks'][array_search($component, ThemeComponents::HOME, true)] ?? null;

            if ($block === null) {
                return null;
            }

            $extra = ['block' => $block, 'headingId' => 'blok-'.(++$state['seq']), 'inverse' => $inverse];

            if ($component === 'hero') {
                $extra['level'] = $state['hero'] === null && ! $state['titled'] ? 1 : 2;
                $state['hero'] ??= $extra['level'];
            }

            $state['titled'] = $state['titled']
                || in_array($component, self::ALWAYS_TITLED, true)
                || (in_array($component, ['text_image', 'feature_grid'], true) && $block['data']['heading'] !== null);
        }

        $band = $top && $tone === 'auto' ? self::BANDS[$state['band']++ % 2] : null;

        return [...$node, ...$extra, 'top' => $top, ...$this->classes($style, $top, $band, isset($extra['children']))];
    }

    /**
     * Kelas node. Isi level atas: `portal-gutter` (latar penuh, isi dibatasi 72rem) dan padding bawaan bagian bila `style`
     * tak mengaturnya. `container`/`columns` level atas: `class` hanya latar dan garis (penuh lebar) dan `innerClass` sisa gaya;
     * lebar isinya dibatasi pembungkus di view, tanpa padding bawaan karena isinya yang bersarang. Bersarang: hanya `style` node itu sendiri.
     *
     * @param  array<string, mixed>  $style
     * @return array{class: string, innerClass?: string}
     */
    private function classes(array $style, bool $top, ?string $band, bool $wrapper): array
    {
        $tone = ($style['tone'] ?? null) === 'auto' ? [] : Arr::only($style, ['tone']);
        $join = fn (array $classes): string => implode(' ', array_filter($classes, fn (string $class): bool => $class !== ''));

        if ($top && $wrapper) {
            return [
                'class' => $join([$band !== null ? $band.' text-gray-900' : '', ThemeStyleMap::classes($tone), ThemeStyleMap::classes(Arr::only($style, ['border']))]),
                'innerClass' => ThemeStyleMap::classes(Arr::except($style, ['tone', 'border'])),
            ];
        }

        return ['class' => $join([
            $top ? 'portal-gutter' : '',
            $band !== null ? $band.' text-gray-900' : '',
            ThemeStyleMap::classes($tone),
            $top && ! isset($style['padding']) ? 'py-16 lg:py-24' : '',
            ThemeStyleMap::classes(Arr::except($style, ['tone'])),
        ])];
    }
}
