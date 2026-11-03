<?php

namespace App\Actions\Konten;

use InvalidArgumentException;

/**
 * Validasi isi paket tema ber-`assets` (F105), dipakai bersama oleh pemasangan dan `pondok:theme-sign`: `handle(folder, manifest)` dengan
 * `$manifest` = keluaran `ValidatePortalTheme`. Folder hanya boleh memuat `theme.json`, `theme.css`, dan `fonts/<nama>.woff2` yang
 * terdaftar (tanpa symlink, berkas lain, atau subfolder); tiap berkas harus cocok size dan sha256 manifest, font bermagic `wOF2`.
 * Lalu CSS diperiksa `ValidateThemeCss`, @font-face (font-display swap, family dan src hanya font manifest, tanpa menimpa font inti),
 * kontras pasangan terdeklarasi (hanya pasangan itu yang dijamin), dan gerak (hanya di dalam prefers-reduced-motion: no-preference).
 * Penolakan = `InvalidArgumentException` berpesan Indonesia.
 */
class ValidateThemePackage
{
    /** Keluarga font inti (`resources/css/app.css` dan `GetPortalTheme::FONTS`) yang tidak boleh ditimpa font tema. */
    private const CORE_FAMILIES = ['plus jakarta sans', 'lora', 'nunito', 'noto naskh arabic'];

    private const NEUTRAL_MOTION = ['none', '0s', '0ms', 'auto'];

    public function __construct(private readonly ValidateThemeCss $css) {}

    /**
     * Path berkas aset yang dideklarasikan manifest (`theme.css` lalu font), relatif terhadap folder paket.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    public static function paths(array $manifest): array
    {
        return array_keys(self::declared($manifest));
    }

    /**
     * Ukuran terdeklarasi tiap berkas aset (path => size).
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, int>
     */
    public static function sizes(array $manifest): array
    {
        return array_map(fn (array $file): int => $file['size'], self::declared($manifest));
    }

    /**
     * @param  array<string, mixed>  $manifest
     *
     * @throws InvalidArgumentException
     */
    public function handle(string $dir, array $manifest): void
    {
        $declared = self::declared($manifest);

        $this->assertNoStrayFiles($dir, $declared);

        $problems = [];

        foreach ($declared as $path => $file) {
            try {
                $this->assertFile($dir, $path, $file);
            } catch (InvalidArgumentException $exception) {
                $problems[] = $exception->getMessage();
            }
        }

        // Semua berkas yang bermasalah dilaporkan sekaligus supaya penerbit melihat semua nilai yang benar dalam satu kali jalan.
        if ($problems !== []) {
            throw new InvalidArgumentException(implode("\n", $problems));
        }

        $fonts = $manifest['assets']['fonts'] ?? [];

        foreach ($fonts as $font) {
            if (file_get_contents("{$dir}/{$font['path']}", false, null, 0, 4) !== 'wOF2') {
                $this->fail('font_magic', ['path' => $font['path']]);
            }
        }

        $parsed = isset($manifest['assets']['css'])
            ? $this->css->handle((string) file_get_contents("{$dir}/theme.css"), $manifest['slug'])
            : ['rules' => [], 'font_faces' => []];

        $this->assertFonts($fonts, $parsed['font_faces']);
        $this->assertContrast($manifest['assets']['contrast'] ?? [], $parsed['rules'], 'body[data-pt-theme="'.$manifest['slug'].'"]');
        $this->assertMotion($parsed['rules']);
    }

    /**
     * Berkas aset ada, bukan symlink, dan ukurannya cocok (tanpa baca isi): untuk pemeriksaan murah saat Rollback dan saat render.
     *
     * @param  array<string, mixed>  $manifest
     *
     * @throws InvalidArgumentException
     */
    public function assertInstalled(string $dir, array $manifest): void
    {
        foreach (self::declared($manifest) as $path => $file) {
            $this->assertPresent($dir, $path, $file['size']);
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, array{sha256: string, size: int}>
     */
    private static function declared(array $manifest): array
    {
        $declared = [];

        foreach ([...(isset($manifest['assets']['css']) ? [$manifest['assets']['css']] : []), ...($manifest['assets']['fonts'] ?? [])] as $file) {
            $declared[$file['path']] = ['sha256' => $file['sha256'], 'size' => $file['size']];
        }

        return $declared;
    }

    /**
     * @param  array<string, array{sha256: string, size: int}>  $declared
     */
    private function assertNoStrayFiles(string $dir, array $declared): void
    {
        $fonts = array_filter(array_keys($declared), fn (string $path): bool => str_starts_with($path, 'fonts/'));

        foreach ($this->entries($dir) as $entry) {
            $path = "{$dir}/{$entry}";

            if (is_link($path)) {
                $this->fail('file_link', ['path' => $entry]);
            }

            if ($entry === 'fonts' && $fonts !== [] && is_dir($path)) {
                foreach ($this->entries($path) as $font) {
                    if (is_link("{$path}/{$font}")) {
                        $this->fail('file_link', ['path' => "fonts/{$font}"]);
                    }

                    if (! in_array("fonts/{$font}", $fonts, true) || ! is_file("{$path}/{$font}")) {
                        $this->fail('file_extra', ['path' => "fonts/{$font}"]);
                    }
                }

                continue;
            }

            if (! is_file($path) || ($entry !== 'theme.json' && ! array_key_exists($entry, $declared))) {
                $this->fail('file_extra', ['path' => $entry]);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function entries(string $dir): array
    {
        return array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
    }

    /**
     * @param  array{sha256: string, size: int}  $file
     */
    private function assertFile(string $dir, string $path, array $file): void
    {
        $this->assertPresent($dir, $path, $file['size']);

        $actual = (string) hash_file('sha256', "{$dir}/{$path}");

        if (! hash_equals($file['sha256'], $actual)) {
            $this->fail('hash_mismatch', ['path' => $path, 'actual' => $actual]);
        }
    }

    private function assertPresent(string $dir, string $path, int $size): void
    {
        $full = "{$dir}/{$path}";

        if (is_link($full) || (str_contains($path, '/') && is_link(dirname($full)))) {
            $this->fail('file_link', ['path' => $path]);
        }

        if (! is_file($full)) {
            $this->fail('file_missing', ['path' => $path]);
        }

        if (filesize($full) !== $size) {
            $this->fail('size_mismatch', ['path' => $path, 'actual' => filesize($full)]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $fonts  font di manifest
     * @param  list<list<array{0: string, 1: string}>>  $faces  @font-face di CSS
     */
    private function assertFonts(array $fonts, array $faces): void
    {
        $families = [];
        $referenced = [];

        foreach ($fonts as $font) {
            if (in_array(strtolower($font['family']), self::CORE_FAMILIES, true)) {
                $this->fail('font_core', ['family' => $font['family']]);
            }

            $families[$font['path']] = $font['family'];
        }

        foreach ($faces as $declarations) {
            $face = [];

            foreach ($declarations as [$property, $value]) {
                $face[$property] = $value;
            }

            $family = trim($face['font-family'] ?? '', '"\' ');

            if (! in_array($family, $families, true)) {
                $this->fail('font_face_family', ['family' => $family]);
            }

            if (strtolower(trim($face['font-display'] ?? '')) !== 'swap') {
                $this->fail('font_face_display', ['family' => $family]);
            }

            foreach (explode(',', $face['src'] ?? '') as $source) {
                if (preg_match('/^\s*url\(\s*["\']([^"\']+)["\']\s*\)\s*format\(\s*["\']woff2["\']\s*\)\s*\z/i', $source, $match) !== 1
                    || ($families[$match[1]] ?? null) !== $family) {
                    $this->fail('font_face_src', ['family' => $family]);
                }

                $referenced[$match[1]] = true;
            }
        }

        foreach (array_keys($families) as $path) {
            if (! isset($referenced[$path])) {
                $this->fail('font_unused', ['path' => $path]);
            }
        }
    }

    /**
     * Tiap pasangan: kedua custom property bernilai literal #rrggbb dan didefinisikan tepat sekali di blok scope tingkat atas.
     *
     * @param  list<array{fg: string, bg: string, size: string}>  $pairs
     * @param  list<array{selectors: list<string>, top: bool, motion: bool, declarations: list<array{0: string, 1: string}>}>  $rules
     */
    private function assertContrast(array $pairs, array $rules, string $scope): void
    {
        $used = [];

        foreach ($pairs as $pair) {
            $used[$pair['fg']] = $used[$pair['bg']] = true;
        }

        $definitions = [];
        $redefined = [];

        foreach ($rules as $rule) {
            $isScope = $rule['top'] && $rule['selectors'] === [$scope];

            foreach ($rule['declarations'] as [$property, $value]) {
                if (! isset($used[$property])) {
                    continue;
                }

                if ($isScope) {
                    $definitions[$property][] = $value;
                } else {
                    $redefined[$property] = true;
                }
            }
        }

        foreach ($pairs as $pair) {
            $colors = [];

            foreach (['fg', 'bg'] as $role) {
                $name = $pair[$role];
                $values = $definitions[$name] ?? [];
                $replace = ['fg' => $pair['fg'], 'bg' => $pair['bg'], 'name' => $name];

                if (count($values) !== 1 || isset($redefined[$name])) {
                    $this->fail('contrast_missing', $replace);
                }

                if (preg_match('/^#[0-9a-f]{6}\z/i', $values[0]) !== 1) {
                    $this->fail('contrast_not_hex', $replace);
                }

                $colors[$role] = $values[0];
            }

            $minimum = $pair['size'] === 'large' ? 3.0 : 4.5;
            $ratio = BuildColorScale::contrast($colors['fg'], $colors['bg']);

            if ($ratio < $minimum) {
                $this->fail('contrast_low', [
                    'fg' => $pair['fg'], 'bg' => $pair['bg'], 'size' => $pair['size'],
                    'ratio' => number_format($ratio, 2, ',', ''), 'min' => number_format($minimum, 1, ',', ''),
                ]);
            }
        }
    }

    /**
     * `animation*`, `transition*`, dan `scroll-behavior` hanya aktif di dalam @media (prefers-reduced-motion: no-preference).
     *
     * @param  list<array{selectors: list<string>, top: bool, motion: bool, declarations: list<array{0: string, 1: string}>}>  $rules
     */
    private function assertMotion(array $rules): void
    {
        foreach ($rules as $rule) {
            if ($rule['motion']) {
                continue;
            }

            foreach ($rule['declarations'] as [$property, $value]) {
                $moves = preg_match('/^(?:-webkit-)?(?:animation|transition)(?:-|\z)|^scroll-behavior\z/', $property) === 1;

                if ($moves && ! in_array(strtolower($value), self::NEUTRAL_MOTION, true)) {
                    $this->fail('motion', ['property' => $property]);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    private function fail(string $key, array $replace = []): never
    {
        throw new InvalidArgumentException(__("pondok.theme_assets.{$key}", $replace));
    }
}
