<?php

namespace App\Actions\Konten;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Peta gaya tema portal (F102): enum gaya -> kelas Tailwind literal. Hanya lookup; tidak ada CSS runtime.
 *
 * Setiap kelas ditulis utuh di sini (tidak dirangkai saat runtime) supaya terpindai Tailwind saat build.
 * Properti punya awalan utilitas sendiri agar tidak bentrok. `padding` dan `columns` boleh responsif
 * (`base`/`md`/`lg`). `tone: auto` dihitung renderer (selang-seling) dan tidak dipetakan. Kelas fokus tidak
 * termasuk peta, jadi tidak bisa ditimpa gaya. Pasangan latar/teks `tone` dijaga kontrasnya oleh test.
 */
class ThemeStyleMap
{
    public const MAP = [
        'tone' => [
            'light' => 'bg-white text-gray-900',
            'muted' => 'bg-gray-100 text-gray-900',
            'brand' => 'bg-brand text-on-brand',
            'dark' => 'bg-primary-950 text-white',
        ],
        'padding' => [
            'base' => ['sm' => 'py-6', 'md' => 'py-8', 'lg' => 'py-12'],
            'md' => ['sm' => 'md:py-6', 'md' => 'md:py-8', 'lg' => 'md:py-12'],
            'lg' => ['sm' => 'lg:py-6', 'md' => 'lg:py-8', 'lg' => 'lg:py-12'],
        ],
        'gap' => ['sm' => 'gap-4', 'md' => 'gap-6', 'lg' => 'gap-8'],
        'align' => ['left' => '', 'center' => 'text-center'],
        'width' => ['narrow' => 'mx-auto max-w-3xl', 'wide' => 'mx-auto max-w-6xl', 'full' => 'max-w-none'],
        'radius' => ['none' => 'rounded-none', 'md' => 'rounded-lg', 'lg' => 'rounded-2xl'],
        'shadow' => ['none' => 'shadow-none', 'sm' => 'shadow-sm'],
        'border' => ['none' => '', 'top' => 'border-t border-current/20', 'bottom' => 'border-b border-current/20'],
        'columns' => [
            'base' => [2 => 'grid-cols-2', 3 => 'grid-cols-3', 4 => 'grid-cols-4'],
            'md' => [2 => 'md:grid-cols-2', 3 => 'md:grid-cols-3', 4 => 'md:grid-cols-4'],
            'lg' => [2 => 'lg:grid-cols-2', 3 => 'lg:grid-cols-3', 4 => 'lg:grid-cols-4'],
        ],
    ];

    /** Properti yang boleh berbentuk `{base, md, lg}`. */
    public const RESPONSIVE = ['padding', 'columns'];

    /**
     * Nilai sah suatu properti (tanpa `auto` untuk tone).
     *
     * @return list<string|int>
     */
    public static function values(string $property): array
    {
        $table = self::MAP[$property] ?? throw new InvalidArgumentException(__('pondok.theme_schema.unknown_style_property', ['property' => Str::limit($property, 80)]));

        return array_keys(in_array($property, self::RESPONSIVE, true) ? $table['base'] : $table);
    }

    /**
     * Kelas Tailwind untuk node `style` yang tervalidasi, dipisah spasi.
     *
     * @param  array<string, mixed>  $style
     *
     * @throws InvalidArgumentException untuk properti/nilai/breakpoint tak dikenal
     */
    public static function classes(array $style): string
    {
        $classes = [];

        foreach ($style as $property => $value) {
            $classes[] = is_array($value)
                ? self::responsive((string) $property, $value)
                : self::lookup((string) $property, $value, 'base');
        }

        return implode(' ', array_filter($classes, fn (string $class): bool => $class !== ''));
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private static function responsive(string $property, array $value): string
    {
        if (! in_array($property, self::RESPONSIVE, true) || ! array_key_exists('base', $value) || array_diff(array_keys($value), ['base', 'md', 'lg']) !== []) {
            throw new InvalidArgumentException(__('pondok.theme_schema.invalid_style_value', ['property' => Str::limit($property, 80), 'value' => Str::limit((string) json_encode($value), 80)]));
        }

        return implode(' ', array_map(fn (string $breakpoint): string => self::lookup($property, $value[$breakpoint], $breakpoint), array_keys($value)));
    }

    private static function lookup(string $property, mixed $value, string $breakpoint): string
    {
        $responsive = in_array($property, self::RESPONSIVE, true);
        $table = self::MAP[$property] ?? throw new InvalidArgumentException(__('pondok.theme_schema.unknown_style_property', ['property' => Str::limit($property, 80)]));
        $table = $responsive ? $table[$breakpoint] : $table;

        if (! in_array($value, array_keys($table), true)) {
            throw new InvalidArgumentException(__('pondok.theme_schema.invalid_style_value', ['property' => Str::limit($property, 80), 'value' => Str::limit((string) json_encode($value), 80)]));
        }

        return $table[$value];
    }
}
