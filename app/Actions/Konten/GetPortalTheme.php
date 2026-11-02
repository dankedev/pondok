<?php

namespace App\Actions\Konten;

use App\Models\Setting;
use Illuminate\Foundation\Vite;

/**
 * Token tema portal (F070) untuk `<style data-portal-theme>` di layout publik.
 *
 * Key `theme.*` dibaca bersama profil lembaga (`GetSchoolProfile::KEYS`) dan divalidasi ulang:
 * nilai yang rusak atau gagal aturan kontras diabaikan per key (pakai bawaan) tanpa exception.
 * CSS hanya berisi hex hasil `BuildColorScale` dan nilai dari peta `FONTS`/`RADII` di server;
 * tidak ada byte dari database yang masuk mentah. Semua bawaan → `css` null (tanpa `<style>`).
 *
 * Font non-bawaan hanya dipakai bila semua aliasnya ada di manifest font build aktif, agar
 * `@fonts($aliases)` tidak pernah melempar `ViteException` di portal.
 *
 * Tema aktif (F104) menyuplai bawaan `palette`/`surface`/`font` di bawah pengaturan admin (lihat `ThemePalette`).

`theme.template` (F072) menyimpan slug tema portal (F102, daftar dari `ListPortalThemes`); tema tidak memengaruhi `css`.
 */
class GetPortalTheme
{
    public const KEYS = ['theme.primary', 'theme.accent', 'theme.font', 'theme.radius', 'theme.template'];

    public const DEFAULT_TEMPLATE = 'default';

    public const DEFAULT_FONT = 'jakarta';

    public const DEFAULT_RADIUS = 'medium';

    /** Pasangan font (OFL, self-host lewat `bunny()` di `vite.config.js`). */
    public const FONTS = [
        'jakarta' => ['css' => [], 'aliases' => null],
        'lora' => [
            'css' => ['--font-heading' => "'Lora', ui-serif, Georgia, serif"],
            'aliases' => ['plus-jakarta-sans', 'lora', 'noto-naskh-arabic'],
        ],
        'nunito' => [
            'css' => ['--font-sans' => "'Nunito', ui-sans-serif, system-ui, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol', 'Noto Color Emoji'"],
            'aliases' => ['nunito', 'noto-naskh-arabic'],
        ],
    ];

    /** Sudut kartu: `--radius-lg` dan `--radius-xl`. */
    public const RADII = [
        'small' => ['--radius-lg' => '.25rem', '--radius-xl' => '.375rem'],
        'medium' => [],
        'large' => ['--radius-lg' => '.75rem', '--radius-xl' => '1.25rem'],
    ];

    /**
     * Warna `#rrggbb` (tanpa trim, tanpa newline akhir).
     */
    public static function isHex(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-f]{6}\z/i', $value) === 1;
    }

    /**
     * Warna merek portal (F071) untuk warna utama, atau null bila bukan hex atau skalanya ditolak aturan kontras.
     * Satu titik untuk token portal dan pratinjau di Situs > Tampilan.
     *
     * @param  array<int, string>|null  $accentScale  skala aksen kustom; null = aksen bawaan
     * @return array{brand: string, brand_hover: string, on_brand: string, brand_text: string, fill_adjusted: bool, text_adjusted: bool}|null
     */
    public static function brandTokens(mixed $hex, ?array $accentScale = null): ?array
    {
        if (! self::isHex($hex)) {
            return null;
        }

        $hex = strtolower($hex);
        $scale = app(BuildColorScale::class)->handle($hex, 'primary');

        if (BuildColorScale::contrastFailure($scale, $accentScale ?? BuildColorScale::DEFAULT_ACCENT) !== null) {
            return null;
        }

        return BuildColorScale::brandColors($hex, $scale);
    }

    /**
     * Slug tema tersimpan; nilai kosong, rusak, atau tanpa folder tema → bawaan.
     * Hanya memeriksa keberadaan folder; validitas manifest dinilai `ResolveActiveTheme`.
     */
    public function template(): string
    {
        return self::installedTheme(Setting::get('theme.template')) ?? self::DEFAULT_TEMPLATE;
    }

    /**
     * @return array{css: ?string, font_aliases: ?list<string>, template: string}
     */
    public function handle(): array
    {
        $values = Setting::getMany(self::KEYS);
        $theme = app(ResolveActiveTheme::class)->handle();

        // Tema aktif (F104) hanya nilai bawaan: pengaturan admin yang terisi menang; aksen tidak pernah dari tema.
        $values['theme.primary'] ??= ThemePalette::PALETTES[$theme['palette'] ?? ''] ?? null;
        $values['theme.font'] ??= $theme['font'] ?? null;
        $build = app(BuildColorScale::class);

        $scales = array_filter([
            'primary' => self::isHex($values['theme.primary']) ? $build->handle($values['theme.primary'], 'primary') : null,
            'accent' => self::isHex($values['theme.accent']) ? $build->handle($values['theme.accent'], 'accent') : null,
        ]);

        // Buang skala yang gagal kontras (aksen bawaan yang gagal berarti primary-lah penyebabnya),
        // lalu nilai ulang sisanya; skala bawaan selalu lolos.
        while ($failure = BuildColorScale::contrastFailure(
            $scales['primary'] ?? BuildColorScale::DEFAULT_PRIMARY,
            $scales['accent'] ?? BuildColorScale::DEFAULT_ACCENT,
        )) {
            unset($scales[isset($scales[$failure['field']]) ? $failure['field'] : 'primary']);
        }

        $font = self::option($values['theme.font'], self::FONTS);

        if ($font === self::DEFAULT_FONT || ($font !== null && ! $this->fontsAvailable(self::FONTS[$font]['aliases']))) {
            $font = null;
        }

        $declarations = [];

        foreach ($scales as $role => $scale) {
            foreach ($scale as $step => $hex) {
                $declarations["--color-{$role}-{$step}"] = $hex;
            }
        }

        if (isset($scales['primary'])) {
            $brand = self::brandTokens($values['theme.primary'], $scales['accent'] ?? null);

            $declarations += $brand === null ? [] : [
                '--color-brand' => $brand['brand'],
                '--color-brand-hover' => $brand['brand_hover'],
                '--color-on-brand' => $brand['on_brand'],
                '--color-brand-text' => $brand['brand_text'],
            ];
        }

        $declarations += ThemePalette::SURFACES[$theme['surface'] ?? ''] ?? [];
        $declarations += $font === null ? [] : self::FONTS[$font]['css'];
        $declarations += self::RADII[self::option($values['theme.radius'], self::RADII) ?? self::DEFAULT_RADIUS];

        $css = implode('', array_map(
            fn (string $name, string $value): string => "{$name}: {$value};",
            array_keys($declarations),
            $declarations,
        ));

        return [
            'css' => $css === '' ? null : ':root{'.$css.'}',
            'font_aliases' => $font === null ? null : self::FONTS[$font]['aliases'],
            'template' => self::installedTheme($values['theme.template']) ?? self::DEFAULT_TEMPLATE,
        ];
    }

    /**
     * Slug berbentuk sah (sama dengan `ResolveActiveTheme`) yang punya folder tema, atau null.
     */
    private static function installedTheme(mixed $slug): ?string
    {
        if (! is_string($slug) || preg_match('/^[a-z0-9-]{2,40}\z/', $slug) !== 1) {
            return null;
        }

        return is_file(rtrim((string) config('pondok.themes_path'), '/')."/{$slug}/theme.json") ? $slug : null;
    }

    /**
     * Kunci opsi yang dikenal, atau null.
     *
     * @param  array<string, mixed>  $options
     */
    private static function option(mixed $value, array $options): ?string
    {
        return is_string($value) && array_key_exists($value, $options) ? $value : null;
    }

    /**
     * Semua alias ada di `families` manifest font build aktif (path sama dengan `ViteFonts::manifest`).
     *
     * @param  list<string>  $aliases
     */
    private function fontsAvailable(array $aliases): bool
    {
        $vite = app(Vite::class);
        $path = $vite->isRunningHot() === true
            ? dirname((string) $vite->hotFile()).'/fonts-manifest.dev.json'
            : public_path('build/fonts-manifest.json');

        $manifest = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $families = is_array($manifest) && is_array($manifest['families'] ?? null) ? $manifest['families'] : [];

        return array_diff($aliases, array_keys($families)) === [];
    }
}
