<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\SavesProfileSettings;
use App\Models\Category;
use App\Models\StaticPage;
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
    use SavesProfileSettings;

    /** @var list<int>|null */
    private ?array $pageIds = null;

    /** @var list<int>|null */
    private ?array $categoryIds = null;

    /**
     * @param  array{primary?: mixed, footer?: mixed, header_button?: mixed}  $data
     */
    public function handle(array $data, User $actor): void
    {
        $this->pageIds = null;
        $this->categoryIds = null;

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

    /**
     * @param  'allowed'|'not_allowed'|'nested'  $group
     * @return array<string, mixed>
     */
    private function item(mixed $row, string $field, int $labelMax, string $group): array
    {
        $row = is_array($row) ? $row : [];
        $type = $row['type'] ?? null;

        if (! is_string($type) || ! in_array($type, GetPortalNavigation::TYPES, true)) {
            $this->fail("{$field}.type", 'nav_type_invalid');
        }

        if ($type === 'group' && $group !== 'allowed') {
            $this->fail("{$field}.type", $group === 'nested' ? 'nav_group_nested' : 'nav_group_not_allowed');
        }

        $label = $this->plainText($row['label'] ?? null, "{$field}.label", $labelMax);

        if ($label === null && in_array($type, ['url', 'group'], true)) {
            $this->fail("{$field}.label", 'label_required');
        }

        $item = ['type' => $type, 'label' => $label];

        return match ($type) {
            'page' => [...$item, 'page_id' => $this->reference($row['page_id'] ?? null, $this->pageIds(), "{$field}.page_id", 'page_not_found')],
            'category' => [...$item, 'category_id' => $this->reference($row['category_id'] ?? null, $this->categoryIds(), "{$field}.category_id", 'category_not_found')],
            'url' => [...$item, 'url' => $this->url($row['url'] ?? null, "{$field}.url")],
            'group' => [...$item, 'children' => $this->children($row['children'] ?? null, "{$field}.children", $labelMax)],
            default => $item,
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function children(mixed $value, string $field, int $labelMax): array
    {
        $rows = is_array($value) ? array_values($value) : [];

        if ($rows === []) {
            $this->fail($field, 'nav_group_empty');
        }

        if (count($rows) > GetPortalNavigation::MAX_CHILDREN) {
            $this->fail($field, 'nav_too_many', ['max' => GetPortalNavigation::MAX_CHILDREN]);
        }

        $children = [];

        foreach ($rows as $index => $row) {
            $children[] = $this->item($row, "{$field}.{$index}", $labelMax, 'nested');
        }

        return $children;
    }

    /**
     * @param  list<int>  $existing
     */
    private function reference(mixed $value, array $existing, string $field, string $reason): int
    {
        $id = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;

        if ($id === false || ! in_array($id, $existing, true)) {
            $this->fail($field, $reason);
        }

        return $id;
    }

    private function url(mixed $value, string $field): string
    {
        $url = is_string($value) ? trim($value) : '';

        if ($url === '') {
            $this->fail($field, 'link_required');
        }

        if (mb_strlen($url) > GetPortalNavigation::URL_MAX_LENGTH) {
            $this->fail($field, 'too_long', ['max' => GetPortalNavigation::URL_MAX_LENGTH]);
        }

        if (! GetPortalNavigation::isSafeTarget($url)) {
            $this->fail($field, 'link_invalid');
        }

        return $url;
    }

    /**
     * @return list<int>
     */
    private function pageIds(): array
    {
        return $this->pageIds ??= StaticPage::query()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    /**
     * Hanya kategori berita: kategori jenis lain tidak bisa dibuka di `/berita`.
     *
     * @return list<int>
     */
    private function categoryIds(): array
    {
        return $this->categoryIds ??= Category::query()->ofType(Category::TYPE_POST)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
    }
}
