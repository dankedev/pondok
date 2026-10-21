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
 */
class GetPortalTheme
{
    public const KEYS = ['theme.primary', 'theme.accent', 'theme.font', 'theme.radius'];

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
     * @return array{css: ?string, font_aliases: ?list<string>}
     */
    public function handle(): array
    {
        $values = Setting::getMany(self::KEYS);
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
        ];
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
