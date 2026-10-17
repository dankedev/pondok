<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\SavesProfileSettings;
use App\Actions\Konten\Concerns\ValidatesNavigationItems;
use App\Models\User;

/**
 * Simpan navigasi portal (halaman Navigasi, F043): menu utama (grup submenu satu tingkat),
 * navigasi footer, dan tombol header. Seluruh input divalidasi sebelum menulis; satu item
 * tidak valid membatalkan semuanya. Tidak ada pemotongan diam-diam: daftar di atas batas ditolak.
 *
 * `null` = key bawaan, `[]` = tanpa item, tombol `false` = tanpa tombol. Halaman draf boleh
 * (tampil setelah terbit). Label disimpan sebagai teks polos.
 */
class UpdatePortalNavigation
{
    use SavesProfileSettings, ValidatesNavigationItems;

    /**
     * @param  array{primary?: mixed, footer?: mixed, header_button?: mixed}  $data
     */
    public function handle(array $data, User $actor): void
    {
        $this->resetNavigationReferences();

        $primary = $this->items($data['primary'] ?? null, 'primary', GetPortalNavigation::MAX_PRIMARY, GetPortalNavigation::LABEL_MAX_MENU, true);
        $footer = $this->items($data['footer'] ?? null, 'footer', GetPortalNavigation::MAX_FOOTER, GetPortalNavigation::LABEL_MAX_FOOTER, false);
        $button = $this->button($data['header_button'] ?? null);

        $this->saveProfileSettings([
            'portal.navigation_primary' => $primary,
            'portal.navigation_footer' => $footer,
            'portal.header_button' => $button,
        ], 'portal_navigation_updated', $actor);
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function items(mixed $value, string $field, int $max, int $labelMax, bool $allowGroups): ?array
    {
        if ($value === null) {
            return null;
        }

        $rows = is_array($value) ? array_values($value) : [];

        if (count($rows) > $max) {
            $this->fail($field, 'nav_too_many', ['max' => $max]);
        }

        $items = [];

        foreach ($rows as $index => $row) {
            $items[] = $this->item($row, "{$field}.{$index}", $labelMax, $allowGroups ? 'allowed' : 'not_allowed');
        }

        return $items;
    }

    /**
     * @return array<string, mixed>|false|null
     */
    private function button(mixed $value): array|false|null
    {
        if ($value === null || $value === false) {
            return $value;
        }

        return $this->item($value, 'header_button', GetPortalNavigation::LABEL_MAX_MENU, 'not_allowed');
    }
}
