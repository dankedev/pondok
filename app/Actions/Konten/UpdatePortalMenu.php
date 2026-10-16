<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\SavesProfileSettings;
use App\Models\StaticPage;
use App\Models\User;

/**
 * Simpan menu header portal (halaman Menu, F037): urutan dan visibilitas item
 * bawaan serta halaman, dan saklar tombol Daftar. Halaman draf boleh dimasukkan
 * (tidak tampil di portal). Batas `visible` dihitung apa pun status tampilnya.
 * Daftar kosong disimpan `null` (menu bawaan).
 */
class UpdatePortalMenu
{
    use SavesProfileSettings;

    /**
     * @param  array{items?: mixed, show_register?: mixed}  $data
     */
    public function handle(array $data, User $actor): void
    {
        $items = $this->items($data['items'] ?? null);

        $this->saveProfileSettings([
            'portal.menu_items' => $items === [] ? null : $items,
            'portal.menu_show_register' => (bool) ($data['show_register'] ?? true),
        ], 'portal_menu_updated', $actor);
    }

    /**
     * @return list<array{type: string, key: string, visible: bool}|array{type: string, page_id: int, label: ?string, visible: bool}>
     */
    private function items(mixed $value): array
    {
        $rows = $this->listInput($value, 'items', GetPortalNavigation::MAX_STORED);
        $pageIds = StaticPage::query()->pluck('id')->all();
        $items = [];
        $seen = [];
        $visible = 0;

        foreach ($rows as $index => $row) {
            $field = "items.{$index}";
            $type = $row['type'] ?? null;
            $isVisible = filter_var($row['visible'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if ($type === 'builtin') {
                $key = $row['key'] ?? null;

                if (! in_array($key, GetPortalNavigation::BUILTIN_KEYS, true)) {
                    $this->fail("{$field}.key", 'menu_item_invalid');
                }

                $id = $key;
                $item = ['type' => 'builtin', 'key' => $key, 'visible' => $isVisible];
                $idField = "{$field}.key";
            } elseif ($type === 'page') {
                $pageId = filter_var($row['page_id'] ?? null, FILTER_VALIDATE_INT);

                if ($pageId === false || ! in_array($pageId, $pageIds, true)) {
                    $this->fail("{$field}.page_id", 'page_not_found');
                }

                $id = "page:{$pageId}";
                $label = $this->plainText($row['label'] ?? null, "{$field}.label", GetPortalNavigation::LABEL_MAX_LENGTH);
                $item = ['type' => 'page', 'page_id' => $pageId, 'label' => $label, 'visible' => $isVisible];
                $idField = "{$field}.page_id";
            } else {
                $this->fail("{$field}.type", 'menu_item_invalid');
            }

            if (isset($seen[$id])) {
                $this->fail($idField, 'menu_item_duplicate');
            }

            $seen[$id] = true;
            $visible += $isVisible ? 1 : 0;
            $items[] = $item;
        }

        if ($visible > GetPortalNavigation::MAX_VISIBLE) {
            $this->fail('items', 'menu_too_many', ['max' => GetPortalNavigation::MAX_VISIBLE]);
        }

        return $items;
    }
}
