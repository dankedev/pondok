<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\WritesInstalledThemes;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Simpan sunting tema buatan sendiri `lokal-*` (F111): `handle(slug, input, pelaku)` mengembalikan versi baru (patch naik).
 * `input` (semua opsional): `tokens` (peta `--nama` => `#rrggbb`, hanya token hex yang didefinisikan sekali di blok scope CSS),
 * `layout` (node puncak `home.layout` utuh, tanpa style responsif), `name`, `description`, `palette`, `surface`, `font`.
 *
 * Tema resmi, tema bukan `lokal-*`, dan tema aktif ditolak. Seluruh paket dibangun di staging lalu divalidasi penuh
 * (`ValidatePortalTheme`, `ValidateThemePackage` termasuk kontras) dan ditukar lewat `swap` (versi lama menjadi `previous.json`),
 * jadi gagal = folder terpasang tidak berubah. Log `edited`.
 */
class SaveEditedTheme
{
    use WritesInstalledThemes;

    private const INPUT_KEYS = ['tokens', 'layout', 'name', 'description', 'palette', 'surface', 'font'];

    public function __construct(
        private readonly ValidatePortalTheme $validate,
        private readonly ValidateThemePackage $package,
        private readonly ValidateThemeCss $css,
        private readonly GetPortalTheme $portalTheme,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return string versi baru
     *
     * @throws InvalidArgumentException
     */
    public function handle(string $slug, array $input, User $actor): string
    {
        $this->authorizeManagement($actor);
        $slug = $this->assertThemeSlug($slug);

        if (! ResolveThemePath::isLocal($slug)) {
            $this->reject('not_local');
        }

        if (($unknown = array_diff(array_keys($input), self::INPUT_KEYS)) !== []) {
            $this->reject('unknown_input', ['keys' => Str::limit(implode(', ', $unknown), 60)]);
        }

        $version = $this->withWriteLock($slug, function () use ($slug, $input): string {
            $directory = $this->themeRoot()."/{$slug}";

            if (! is_file("{$directory}/theme.json")) {
                $this->reject('not_installed');
            }

            if ($this->portalTheme->template() === $slug) {
                $this->reject('active_theme');
            }

            $current = $this->validate->handle("{$directory}/theme.json");
            $raw = json_decode((string) file_get_contents("{$directory}/theme.json"), true);
            $files = isset($current['assets']) ? $this->installedAssetFiles($directory, $current) : [];

            foreach (['name', 'description', 'palette', 'surface', 'font'] as $key) {
                if (array_key_exists($key, $input)) {
                    $raw[$key] = $input[$key];
                }
            }

            if (array_key_exists('layout', $input)) {
                $this->assertLayout($input['layout']);
                $raw['home'] = ['layout' => $input['layout']];
            }

            if (array_key_exists('tokens', $input)) {
                [$files, $raw] = $this->applyTokens($slug, $input['tokens'], $files, $raw);
            }

            [$major, $minor, $patch] = explode('.', $current['version']);
            $raw['version'] = "{$major}.{$minor}.".((int) $patch + 1);

            $manifest = $this->validate->handle($this->stage($slug, $this->encodeManifest($raw)));

            $this->stageAssets($this->package, $slug, $manifest, $files);
            $this->swap($slug, $manifest);

            return $manifest['version'];
        });

        activity('konten')
            ->causedBy($actor)
            ->event('edited')
            ->withProperties(['slug' => $slug, 'version' => $version])
            ->log('portal_theme_edited');

        return $version;
    }

    /**
     * Layout tidak kosong dan style node puncak tidak responsif (array hanya boleh berisi `base`); sisanya diperiksa skema.
     */
    private function assertLayout(mixed $layout): void
    {
        if (! is_array($layout) || $layout === []) {
            $this->reject('layout_invalid');
        }

        foreach ($layout as $node) {
            foreach (is_array($node) && is_array($node['style'] ?? null) ? $node['style'] : [] as $value) {
                if (is_array($value) && array_keys($value) !== ['base']) {
                    $this->reject('layout_invalid');
                }
            }
        }
    }

    /**
     * Ganti nilai token di blok scope CSS, lalu pastikan hasil parse hanya berbeda pada token itu (teks yang salah tempat = ditolak).
     *
     * @param  array<string, string>  $files
     * @param  array<string, mixed>  $raw
     * @return array{0: array<string, string>, 1: array<string, mixed>}
     */
    private function applyTokens(string $slug, mixed $tokens, array $files, array $raw): array
    {
        if (! is_array($tokens) || $tokens === []) {
            $this->reject('token_invalid');
        }

        foreach ($tokens as $name => $value) {
            if (! is_string($name) || preg_match('/^--[a-z0-9][a-z0-9-]{0,40}\z/', $name) !== 1
                || ! is_string($value) || preg_match('/^#[0-9a-f]{6}\z/i', $value) !== 1) {
                $this->reject('token_invalid');
            }
        }

        $css = $files['theme.css'] ?? '';
        $scope = $this->scope($slug);
        $before = $css === '' ? [] : $this->css->handle($css, $slug)['rules'];

        foreach (array_keys($tokens) as $name) {
            $defined = [];

            foreach ($before as $rule) {
                if ($rule['top'] && $rule['selectors'] === [$scope]) {
                    $defined = [...$defined, ...array_filter($rule['declarations'], fn (array $declaration): bool => $declaration[0] === $name)];
                }
            }

            if (count($defined) !== 1 || preg_match('/^#[0-9a-f]{6}\z/i', $defined[array_key_first($defined)][1]) !== 1) {
                $this->reject('token_unknown', ['token' => Str::limit($name, 40)]);
            }
        }

        foreach ($tokens as $name => $value) {
            $css = preg_replace_callback(
                '/'.preg_quote($scope, '/').'\s*\{[^{}]*\}/',
                fn (array $block): string => preg_replace('/(?<![\w-])'.preg_quote($name, '/').'(\s*:\s*)#[0-9a-f]{6}(?![0-9a-f])/i', $name.'${1}'.$value, $block[0], 1) ?? $block[0],
                $css,
            ) ?? $css;
        }

        $expected = array_map(fn (array $rule): array => $rule['top'] && $rule['selectors'] === [$scope]
            ? [...$rule, 'declarations' => array_map(fn (array $declaration): array => isset($tokens[$declaration[0]]) ? [$declaration[0], $tokens[$declaration[0]]] : $declaration, $rule['declarations'])]
            : $rule, $before);

        if ($this->css->handle($css, $slug)['rules'] !== $expected) {
            $this->reject('css_edit_failed');
        }

        $files['theme.css'] = $css;
        $raw['assets']['css'] = [...$raw['assets']['css'], 'sha256' => hash('sha256', $css), 'size' => strlen($css)];

        return [$files, $raw];
    }
}
