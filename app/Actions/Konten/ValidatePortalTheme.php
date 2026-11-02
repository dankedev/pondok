<?php

namespace App\Actions\Konten;

use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

/**
 * Validasi `theme.json` (F102, skema v1) dan kembalikan pohon ternormalisasi; set kunci tertutup, tanpa string bebas.
 *
 * Urutan: ukuran berkas (sebelum decode), UTF-8, decode (depth longgar, hanya pengaman teknis), versi skema,
 * kunci puncak, metadata, lalu pohon `header`/`footer`/`home.layout` (kedalaman <= 6 dan node <= 150 setelah decode).
 * Slug harus sama dengan nama direktori tema. Semua penolakan berupa `InvalidArgumentException` berpesan Indonesia.
 */
class ValidatePortalTheme
{
    public const MAX_BYTES = 65536;

    public const MAX_DEPTH = 6;

    public const MAX_NODES = 150;

    /** Versi API tema inti; `min_core` tema tidak boleh melebihinya. */
    public const CORE_VERSION = '1.0.0';

    private const JSON_DEPTH = 64;

    private const ROOT_KEYS = ['schema_version', 'slug', 'name', 'description', 'version', 'min_core', 'header', 'footer', 'home'];

    private const REQUIRED_KEYS = ['schema_version', 'slug', 'name', 'description', 'version', 'min_core'];

    private const BREAKPOINTS = ['base', 'md', 'lg'];

    public function __construct(private readonly MigrateThemeSchema $migrate) {}

    /**
     * @param  string  $path  path `theme.json`; nama direktori induknya = slug
     * @return array{schema_version: int, slug: string, name: string, description: string, version: string, min_core: string, header: ?array<string, mixed>, footer: ?array<string, mixed>, home: array{layout: list<array<string, mixed>>}}
     *
     * @throws InvalidArgumentException
     */
    public function handle(string $path): array
    {
        $manifest = $this->migrate->handle($this->decode($path));

        $this->assertKeys($manifest, self::ROOT_KEYS, 'theme.json');

        foreach (self::REQUIRED_KEYS as $key) {
            if (! array_key_exists($key, $manifest)) {
                $this->fail('missing_key', ['key' => $key]);
            }
        }

        $this->assertMetadata($manifest, basename(dirname($path)));

        $count = 0;

        foreach (['header', 'footer'] as $slot) {
            if (isset($manifest[$slot])) {
                $this->assertNode($manifest[$slot], $slot, $slot, 1, $count);
            }
        }

        $layout = $this->assertHome($manifest['home'] ?? [], $count);

        return [
            ...$manifest,
            'header' => $manifest['header'] ?? null,
            'footer' => $manifest['footer'] ?? null,
            'home' => ['layout' => $layout],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $path): array
    {
        if (! is_file($path)) {
            $this->fail('file_missing');
        }

        if ((int) filesize($path) > self::MAX_BYTES) {
            $this->fail('file_too_large', ['max' => self::MAX_BYTES / 1024]);
        }

        $raw = (string) file_get_contents($path);

        if (! mb_check_encoding($raw, 'UTF-8')) {
            $this->fail('invalid_utf8');
        }

        try {
            $manifest = json_decode($raw, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->fail($e->getCode() === JSON_ERROR_DEPTH ? 'json_too_nested' : 'invalid_json');
        }

        if (! is_array($manifest) || array_is_list($manifest)) {
            $this->fail('root_not_object');
        }

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function assertMetadata(array $manifest, string $directory): void
    {
        if (! is_string($manifest['slug']) || preg_match('/^[a-z0-9-]{2,40}\z/', $manifest['slug']) !== 1) {
            $this->fail('invalid_slug');
        }

        if ($manifest['slug'] !== $directory) {
            $this->fail('slug_mismatch', ['slug' => $manifest['slug'], 'directory' => $directory]);
        }

        foreach (['name' => 60, 'description' => 300] as $key => $max) {
            if (! is_string($manifest[$key]) || trim($manifest[$key]) === '' || mb_strlen($manifest[$key]) > $max) {
                $this->fail('invalid_text', ['key' => $key, 'max' => $max]);
            }
        }

        foreach (['version', 'min_core'] as $key) {
            if (! is_string($manifest[$key]) || preg_match('/^\d+\.\d+\.\d+\z/', $manifest[$key]) !== 1) {
                $this->fail('invalid_version', ['key' => $key]);
            }
        }

        if (version_compare($manifest['min_core'], self::CORE_VERSION, '>')) {
            $this->fail('core_too_old', ['required' => $manifest['min_core'], 'core' => self::CORE_VERSION]);
        }
    }

    /**
     * Tanpa `home.layout` = preset `blocks` (blok beranda dirender oleh view legacy).
     *
     * @return list<array<string, mixed>>
     */
    private function assertHome(mixed $home, int &$count): array
    {
        $this->assertObject($home, 'home');
        $this->assertKeys($home, ['layout'], 'home');

        if (! array_key_exists('layout', $home)) {
            return [['component' => 'blocks', 'variant' => 'default']];
        }

        $layout = $home['layout'];

        if (! is_array($layout) || ! array_is_list($layout)) {
            $this->fail('invalid_layout');
        }

        foreach ($layout as $index => $node) {
            $this->assertNode($node, 'home', "home.layout.{$index}", 1, $count);
        }

        foreach ($layout as $node) {
            if ($node['component'] === 'blocks' && count($layout) !== 1) {
                $this->fail('blocks_alone');
            }
        }

        return $layout;
    }

    private function assertNode(mixed $node, string $slot, string $where, int $depth, int &$count): void
    {
        $this->assertObject($node, $where);
        $this->assertKeys($node, ['component', 'variant', 'style', 'children'], $where);
        $this->assertCount($where, $depth, $count);

        $component = $node['component'] ?? null;

        if (! is_string($component) || ! in_array($component, ThemeComponents::SLOTS[$slot], true)) {
            $this->fail('component_not_allowed', ['component' => json_encode($component), 'where' => $slot]);
        }

        if ($component === 'blocks' && ($depth > 1 || array_key_exists('style', $node))) {
            $this->fail('blocks_alone');
        }

        $children = $node['children'] ?? null;

        if (array_key_exists('children', $node)) {
            if (! in_array($component, ['container', 'columns'], true)) {
                $this->fail('children_not_allowed', ['where' => $where]);
            }

            if (! is_array($children) || ! array_is_list($children)) {
                $this->fail('invalid_children', ['where' => $where]);
            }
        }

        if ($component === 'columns' && (count($children ?? []) < 2 || count($children) > 4)) {
            $this->fail('columns_children', ['where' => $where]);
        }

        $this->assertAppearance($node, $component, $where, auto: $slot === 'home' && $depth === 1, cells: count($children ?? []));

        foreach ($children ?? [] as $index => $child) {
            $this->assertNode($child, $slot, "{$where}.children.{$index}", $depth + 1, $count);
        }
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function assertAppearance(array $node, string $component, string $where, bool $auto, int $cells): void
    {
        if (array_key_exists('variant', $node) && (! is_string($node['variant']) || ! in_array($node['variant'], ThemeComponents::VARIANTS[$component] ?? [], true))) {
            $this->fail('invalid_variant', ['variant' => json_encode($node['variant']), 'component' => $component, 'where' => $where]);
        }

        if (array_key_exists('style', $node)) {
            $this->assertStyle($node['style'], "{$where}.style", $auto, $component === 'columns' ? $cells : null);
        }
    }

    /**
     * @param  ?int  $cells  jumlah anak bila node = `columns` (satu-satunya yang boleh `style.columns`), selain itu null
     */
    private function assertStyle(mixed $style, string $where, bool $auto, ?int $cells): void
    {
        $this->assertObject($style, $where, 'invalid_style');
        $this->assertKeys($style, array_keys(ThemeStyleMap::MAP), $where);

        foreach ($style as $property => $value) {
            $allowed = ThemeStyleMap::values($property);
            $allowed = $property === 'tone' && $auto ? ['auto', ...$allowed] : $allowed;

            if (is_array($value)) {
                $valid = in_array($property, ThemeStyleMap::RESPONSIVE, true)
                    && array_key_exists('base', $value)
                    && array_diff(array_keys($value), self::BREAKPOINTS) === []
                    && array_filter($value, fn (mixed $item): bool => ! in_array($item, $allowed, true)) === [];
            } else {
                $valid = in_array($value, $allowed, true);
            }

            if (! $valid && $property === 'tone' && $value === 'auto') {
                $this->fail('tone_auto_home_only', ['where' => $where]);
            }

            if (! $valid) {
                $this->fail('invalid_style_value', ['property' => $property, 'value' => json_encode($value)]);
            }

            if ($property === 'columns') {
                if ($cells === null) {
                    $this->fail('columns_style_only', ['where' => $where]);
                }

                if (max(is_array($value) ? $value : [$value]) > $cells) {
                    $this->fail('columns_style_too_wide', ['where' => $where, 'cells' => $cells]);
                }
            }
        }
    }

    private function assertCount(string $where, int $depth, int &$count): void
    {
        if ($depth > self::MAX_DEPTH) {
            $this->fail('too_deep', ['max' => self::MAX_DEPTH, 'where' => $where]);
        }

        if (++$count > self::MAX_NODES) {
            $this->fail('too_many_nodes', ['max' => self::MAX_NODES]);
        }
    }

    /**
     * Objek JSON = array asosiatif (atau kosong).
     */
    private function assertObject(mixed $value, string $where, string $reason = 'invalid_node'): void
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->fail($reason, ['where' => $where]);
        }
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $allowed
     */
    private function assertKeys(array $value, array $allowed, string $where): void
    {
        $unknown = array_diff(array_map('strval', array_keys($value)), $allowed);

        if ($unknown !== []) {
            $this->fail('unknown_keys', ['where' => $where, 'keys' => implode(', ', $unknown)]);
        }
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    private function fail(string $key, array $replace = []): never
    {
        // Nilai dari berkas tema dipotong supaya pesan (tampil di halaman Tampilan dan log) tetap pendek.
        $replace = array_map(fn (mixed $value): mixed => is_string($value) ? Str::limit($value, 80) : $value, $replace);

        throw new InvalidArgumentException(__("pondok.theme_schema.{$key}", $replace));
    }
}
