<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\SavesProfileSettings;
use App\Models\Setting;
use App\Models\User;

/**
 * Simpan tema portal (F070): warna utama dan aksen (`#rrggbb` huruf kecil), pasangan font, dan
 * sudut kartu di `theme.*`. Nilai yang sama dengan bawaan disimpan null; key yang tidak dikirim
 * tidak diubah. Skala hasil `BuildColorScale` (dari warna masukan atau yang tersimpan) harus lolos
 * aturan kontras 4,5:1. Semua galat dilempar sebelum simpan;
 * log `portal_theme_updated` hanya bila ada perubahan.
 */
class UpdatePortalTheme
{
    use SavesProfileSettings;

    /**
     * Key yang tidak dikirim tidak diubah; null atau '' mengembalikan ke bawaan.
     *
     * @param  array{primary?: mixed, accent?: mixed, font?: mixed, radius?: mixed}  $data
     */
    public function handle(array $data, User $actor): void
    {
        $values = [];

        foreach (['primary', 'accent'] as $field) {
            if (array_key_exists($field, $data)) {
                $values["theme.{$field}"] = $this->color($data[$field], $field);
            }
        }

        if (array_key_exists('font', $data)) {
            $values['theme.font'] = $this->option($data['font'], 'font', GetPortalTheme::FONTS, GetPortalTheme::DEFAULT_FONT);
        }

        if (array_key_exists('radius', $data)) {
            $values['theme.radius'] = $this->option($data['radius'], 'radius', GetPortalTheme::RADII, GetPortalTheme::DEFAULT_RADIUS);
        }

        $this->ensureReadable(
            array_key_exists('theme.primary', $values) ? $values['theme.primary'] : Setting::get('theme.primary'),
            array_key_exists('theme.accent', $values) ? $values['theme.accent'] : Setting::get('theme.accent'),
        );

        $this->saveProfileSettings($values, 'portal_theme_updated', $actor);
    }

    /**
     * Skala hasil `BuildColorScale` dari warna efektif (masukan, atau yang tersimpan) harus lolos
     * aturan kontras; warna kosong atau rusak memakai skala bawaan.
     */
    private function ensureReadable(mixed $primary, mixed $accent): void
    {
        $build = app(BuildColorScale::class);
        $failure = BuildColorScale::contrastFailure(
            GetPortalTheme::isHex($primary) ? $build->handle($primary, 'primary') : BuildColorScale::DEFAULT_PRIMARY,
            GetPortalTheme::isHex($accent) ? $build->handle($accent, 'accent') : BuildColorScale::DEFAULT_ACCENT,
        );

        if ($failure !== null) {
            $ratio = number_format(floor($failure['ratio'] * 10) / 10, 1, ',', '');

            $this->fail($failure['field'], "theme_{$failure['field']}_contrast", ['ratio' => "{$ratio}:1"]);
        }
    }

    /**
     * Kosong → null; selain itu `#rrggbb` (di-trim) → huruf kecil.
     */
    private function color(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $hex = is_string($value) ? trim($value) : null;

        if (! GetPortalTheme::isHex($hex)) {
            $this->fail($field, 'theme_hex_invalid');
        }

        return strtolower($hex);
    }

    /**
     * Kunci opsi yang dikenal; kosong atau bawaan → null.
     *
     * @param  array<string, mixed>  $options
     */
    private function option(mixed $value, string $field, array $options, string $default): ?string
    {
        if ($value === null || $value === '' || $value === $default) {
            return null;
        }

        if (! is_string($value) || ! array_key_exists($value, $options)) {
            $this->fail($field, 'theme_option_invalid');
        }

        return $value;
    }
}
