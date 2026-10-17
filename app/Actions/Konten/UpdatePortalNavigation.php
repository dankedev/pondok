<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\SavesProfileSettings;
use App\Actions\Konten\Concerns\ValidatesNavigationItems;
use App\Models\User;

/**
 * Simpan navigasi portal (halaman Navigasi, F043): menu utama (grup submenu satu tingkat)
 * dan tombol header. Tautan footer ada di widget footer (F046, `UpdatePortalFooter`). Seluruh input divalidasi sebelum menulis; satu item
 * tidak valid membatalkan semuanya. Tidak ada pemotongan diam-diam: daftar di atas batas ditolak.
 *
 * `null` = key bawaan, `[]` = tanpa item, tombol `false` = tanpa tombol. Halaman draf boleh
 * (tampil setelah terbit). Label disimpan sebagai teks polos.
 */
class UpdatePortalNavigation
{
    use SavesProfileSettings, ValidatesNavigationItems;

    /**
     * @param  array{primary?: mixed, header_button?: mixed}  $data
     */
    public function handle(array $data, User $actor): void
    {
        $this->resetNavigationReferences();

        $primary = $this->items($data['primary'] ?? null);
        $button = $this->button($data['header_button'] ?? null);

        $this->saveProfileSettings([
            'portal.navigation_primary' => $primary,
            'portal.header_button' => $button,
        ], 'portal_navigation_updated', $actor);
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function items(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $rows = is_array($value) ? array_values($value) : [];

        if (count($rows) > GetPortalNavigation::MAX_PRIMARY) {
            $this->fail('primary', 'nav_too_many', ['max' => GetPortalNavigation::MAX_PRIMARY]);
        }

        $items = [];

        foreach ($rows as $index => $row) {
            $items[] = $this->item($row, "primary.{$index}", GetPortalNavigation::LABEL_MAX_MENU, 'allowed');
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
