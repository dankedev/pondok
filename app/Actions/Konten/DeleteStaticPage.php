<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\ValidatesContentInput;
use App\Models\Setting;
use App\Models\StaticPage;
use Illuminate\Support\Facades\DB;

/**
 * Hapus permanen halaman bebas (F037) dan lepas rujukannya dari menu portal dan
 * tautan footer dalam satu transaksi, agar ID yang kelak dipakai ulang DB tidak
 * muncul di menu. Guard akhir halaman sistem: super-admin melewati policy.
 */
class DeleteStaticPage
{
    use ValidatesContentInput;

    /** Key settings berisi daftar item ber-`page_id`. */
    public const REFERENCE_KEYS = ['portal.menu_items', 'portal.footer_links'];

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

                $kept = array_values(array_filter(
                    $items,
                    fn (mixed $item): bool => ! is_array($item) || ! isset($item['page_id']) || (int) $item['page_id'] !== $page->id,
                ));

                if (count($kept) !== count($items)) {
                    Setting::set($key, $kept === [] ? null : $kept);
                }
            }
        });
    }
}
