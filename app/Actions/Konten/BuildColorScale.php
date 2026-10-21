<?php

namespace App\Actions\Konten;

/**
 * Skala warna portal 50–950 dari satu warna dasar (F070), dihitung di ruang OKLCH.
 *
 * Tiap peran punya tangga lightness (L) dan chroma (C) tetap yang diambil dari skala
 * `resources/css/app.css`. Warna masukan ditaruh persis di langkah dengan L tangga
 * terdekat (seri → langkah lebih gelap); langkah lain memakai L tangga, hue masukan, dan
 * chroma masukan yang diskalakan menurut tangga, lalu diturunkan sampai masuk gamut sRGB.
 * Karena L tetap, kontras teks praktis terjamin; `contrastFailure()` adalah jaring pengamannya.
 */
class BuildColorScale
{
    public const STEPS = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];

    /** Kontras minimal teks (WCAG AA). */
    public const MIN_CONTRAST = 4.5;

    /** Skala bawaan `app.css` (design guide §2), dipakai bila warna tidak diatur. */
    public const DEFAULT_PRIMARY = [
        50 => '#eef2fb', 100 => '#dde4f6', 200 => '#bbc8ec', 300 => '#8ea2db', 400 => '#5f78c4', 500 => '#3d56a8',
        600 => '#273e8c', 700 => '#1a2c72', 800 => '#0f1d59', 900 => '#061247', 950 => '#000a3c',
    ];

    public const DEFAULT_ACCENT = [
        50 => '#fff9e5', 100 => '#fff0bf', 200 => '#ffe28a', 300 => '#ffd452', 400 => '#ffc524', 500 => '#f8b800',
        600 => '#d49a00', 700 => '#a67700', 800 => '#7a5700', 900 => '#523a00', 950 => '#2e2100',
    ];

    /** @var array<string, array{l: list<float>, c: list<float>}> */
    private const LADDERS = [
        'primary' => [
            'l' => [.961, .919, .835, .718, .588, .478, .394, .326, .264, .217, .183],
            'c' => [.013, .026, .052, .087, .120, .135, .133, .123, .109, .100, .097],
        ],
        'accent' => [
            'l' => [.981, .955, .918, .884, .852, .820, .723, .601, .481, .366, .256],
            'c' => [.027, .065, .112, .151, .168, .168, .149, .124, .099, .075, .052],
        ],
    ];

    /**
     * @param  string  $hex  `#rrggbb` (huruf bebas)
     * @param  'primary'|'accent'  $role
     * @return array<int, string> langkah 50..950 → `#rrggbb` huruf kecil
     */
    public function handle(string $hex, string $role): array
    {
        $ladder = self::LADDERS[$role];
        $hex = strtolower($hex);
        [$lightness, $chroma, $hue] = self::toOklch($hex);

        if ($chroma < 0.0001) {
            [$chroma, $hue] = [0.0, 0.0];
        }

        $anchor = 0;

        foreach ($ladder['l'] as $index => $stepLightness) {
            if (abs($stepLightness - $lightness) <= abs($ladder['l'][$anchor] - $lightness)) {
                $anchor = $index;
            }
        }

        $scale = [];

        foreach (self::STEPS as $index => $step) {
            $scale[$step] = $index === $anchor
                ? $hex
                : self::inGamutHex($ladder['l'][$index], $chroma * $ladder['c'][$index] / $ladder['c'][$anchor], $hue);
        }

        return $scale;
    }

    /**
     * Rasio kontras WCAG 2.x antara dua warna `#rrggbb`.
     */
    public static function contrast(string $a, string $b): float
    {
        $lighter = max(self::luminance($a), self::luminance($b));
        $darker = min(self::luminance($a), self::luminance($b));

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * Aturan kontras atas hasil skala. Primary gagal bila putih vs 700/800/900/950 atau
     * 100/200 vs 950 di bawah 4,5:1; accent gagal bila 400/500 vs primary-950 di bawah 4,5:1.
     * Mengembalikan field pertama yang gagal dengan rasio terendahnya, atau null. Langkah dibaca
     * per kunci langkah, atau per posisi bila kunci itu tidak ada (skala berbentuk daftar 11 warna).
     *
     * @param  array<int, string>  $primaryScale
     * @param  array<int, string>  $accentScale
     * @return array{field: 'primary'|'accent', ratio: float}|null
     */
    public static function contrastFailure(array $primaryScale, array $accentScale): ?array
    {
        $primaryScale = self::byStep($primaryScale);
        $accentScale = self::byStep($accentScale);

        $checks = [
            'primary' => [
                ...array_map(fn (int $step): array => ['#ffffff', $primaryScale[$step]], [700, 800, 900, 950]),
                ...array_map(fn (int $step): array => [$primaryScale[$step], $primaryScale[950]], [100, 200]),
            ],
            'accent' => array_map(fn (int $step): array => [$accentScale[$step], $primaryScale[950]], [400, 500]),
        ];

        foreach ($checks as $field => $pairs) {
            $ratio = min(array_map(fn (array $pair): float => self::contrast(...$pair), $pairs));

            if ($ratio < self::MIN_CONTRAST) {
                return ['field' => $field, 'ratio' => $ratio];
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $scale
     * @return array<int, string>
     */
    private static function byStep(array $scale): array
    {
        $steps = [];

        foreach (self::STEPS as $index => $step) {
            $steps[$step] = $scale[$step] ?? $scale[$index] ?? '#000000';
        }

        return $steps;
    }

    private static function luminance(string $hex): float
    {
        [$red, $green, $blue] = array_map(function (float $channel): float {
            return $channel <= 0.03928 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
        }, self::channels($hex));

        return 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;
    }

    /**
     * @return array{float, float, float} kanal sRGB 0..1
     */
    private static function channels(string $hex): array
    {
        return array_map(fn (string $pair): float => hexdec($pair) / 255, str_split(substr($hex, 1), 2));
    }

    /**
     * @return array{float, float, float} [L, C, h derajat]
     */
    private static function toOklch(string $hex): array
    {
        [$red, $green, $blue] = array_map(
            fn (float $channel): float => $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4,
            self::channels($hex),
        );

        $l = (0.4122214708 * $red + 0.5363325363 * $green + 0.0514459929 * $blue) ** (1 / 3);
        $m = (0.2119034982 * $red + 0.6806995451 * $green + 0.1073969566 * $blue) ** (1 / 3);
        $s = (0.0883024619 * $red + 0.2817188376 * $green + 0.6299787005 * $blue) ** (1 / 3);

        $lightness = 0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s;
        $a = 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s;
        $b = 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s;

        return [$lightness, sqrt($a ** 2 + $b ** 2), fmod(rad2deg(atan2($b, $a)) + 360, 360)];
    }

    /**
     * @return array{float, float, float} sRGB linear (boleh di luar 0..1)
     */
    private static function toLinearRgb(float $lightness, float $chroma, float $hue): array
    {
        $a = $chroma * cos(deg2rad($hue));
        $b = $chroma * sin(deg2rad($hue));

        $l = ($lightness + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m = ($lightness - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($lightness - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        return [
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
        ];
    }

    private static function inGamut(float $lightness, float $chroma, float $hue): bool
    {
        foreach (self::toLinearRgb($lightness, $chroma, $hue) as $channel) {
            if ($channel < -0.00001 || $channel > 1.00001) {
                return false;
            }
        }

        return true;
    }

    /**
     * Turunkan chroma (bisection) sampai warna masuk gamut sRGB, lalu tulis sebagai hex.
     */
    private static function inGamutHex(float $lightness, float $chroma, float $hue): string
    {
        if (! self::inGamut($lightness, $chroma, $hue)) {
            [$low, $high] = [0.0, $chroma];

            for ($i = 0; $i < 24; $i++) {
                $mid = ($low + $high) / 2;
                self::inGamut($lightness, $mid, $hue) ? $low = $mid : $high = $mid;
            }

            $chroma = $low;
        }

        return '#'.implode('', array_map(function (float $channel): string {
            $encoded = $channel <= 0.0031308 ? 12.92 * $channel : 1.055 * $channel ** (1 / 2.4) - 0.055;

            return sprintf('%02x', (int) round(max(0.0, min(1.0, $encoded)) * 255));
        }, self::toLinearRgb($lightness, $chroma, $hue)));
    }
}
