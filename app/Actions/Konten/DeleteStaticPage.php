<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\ValidatesContentInput;
use App\Models\Setting;
use App\Models\StaticPage;
use Illuminate\Support\Facades\DB;

/**
 * Hapus permanen halaman bebas (F037) dan lepas rujukannya dari navigasi portal (F043)
 * dalam satu transaksi, agar ID yang kelak dipakai ulang DB tidak muncul di menu: item
 * menu utama (akar dan anak grup; grup yang jadi kosong ikut dibuang), navigasi footer,
 * dan tombol header (menjadi `false`). Daftar yang jadi kosong disimpan `[]` supaya menu
 * bawaan tidak muncul lagi; key yang belum diatur tidak disentuh. Guard akhir halaman
 * sistem: super-admin melewati policy.
 */
class DeleteStaticPage
{
    use ValidatesContentInput;

    /** Key settings berisi daftar item ber-`page_id`. */
    public const REFERENCE_KEYS = ['portal.navigation_primary', 'portal.navigation_footer'];

    public function handle(StaticPage $page): void
    {
        if ($page->isSystem()) {
            $this->fail('page', 'page_system_locked');
        }

        DB::transaction(function () use ($page): void {
            $page->delete();

            foreach (self::REFERENCE_KEYS as $key) {
                $items = Setting::get($key);

                if (! is_array($items)) {
                    continue;
                }

                $kept = $this->withoutPage($items, $page->id);

                if ($kept !== $items) {
                    Setting::set($key, $kept);
                }
            }

            if ($this->refersTo(Setting::get('portal.header_button'), $page->id)) {
                Setting::set('portal.header_button', false);
            }
        });
    }

    /**
     * @param  array<mixed>  $items
     * @return array<mixed>
     */
    private function withoutPage(array $items, int $pageId): array
    {
        $kept = [];
        $changed = false;

        foreach ($items as $item) {
            if ($this->refersTo($item, $pageId)) {
                $changed = true;

                continue;
            }

            if (is_array($item) && ($item['type'] ?? null) === 'group' && is_array($item['children'] ?? null)) {
                $children = $this->withoutPage($item['children'], $pageId);

                if ($children !== $item['children']) {
                    $changed = true;

                    if ($children === []) {
                        continue;
                    }

                    $item['children'] = $children;
                }
            }

            $kept[] = $item;
        }

        return $changed ? $kept : $items;
    }

    private function refersTo(mixed $item, int $pageId): bool
    {
        return is_array($item) && isset($item['page_id']) && is_scalar($item['page_id']) && (int) $item['page_id'] === $pageId;
    }
}
