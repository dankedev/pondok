<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\SavesProfileSettings;
use App\Models\Setting;
use App\Models\StaticPage;
use App\Models\User;

/**
 * Aksi "Tambahkan ke menu utama" di edit halaman (F043): tambahkan halaman di akhir
 * menu utama. Menu bawaan dimaterialisasi lebih dulu agar item lain tetap. Halaman yang
 * sudah dirujuk (di akar atau anak grup) tidak digandakan; menu penuh ditolak tanpa menulis.
 */
class AddPageToPrimaryNavigation
{
    use SavesProfileSettings;

    public const ADDED = 'added';

    public const ALREADY_LISTED = 'already_listed';

    public function handle(StaticPage $page, User $actor): string
    {
        $stored = Setting::get('portal.navigation_primary');
        $items = is_array($stored) ? array_values($stored) : GetPortalNavigation::materializedDefaultPrimary();

        if ($this->isListed($items, $page->id)) {
            return self::ALREADY_LISTED;
        }

        if (count($items) >= GetPortalNavigation::MAX_PRIMARY) {
            $this->fail('primary', 'nav_menu_full', ['max' => GetPortalNavigation::MAX_PRIMARY]);
        }

        $items[] = ['type' => 'page', 'label' => null, 'page_id' => $page->id];

        $this->saveProfileSettings(['portal.navigation_primary' => $items], 'portal_navigation_updated', $actor);

        return self::ADDED;
    }

    /**
     * @param  list<mixed>  $items
     */
    private function isListed(array $items, int $pageId): bool
    {
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (($item['type'] ?? null) === 'page' && (int) ($item['page_id'] ?? 0) === $pageId) {
                return true;
            }

            if (($item['type'] ?? null) === 'group' && is_array($item['children'] ?? null) && $this->isListed(array_values($item['children']), $pageId)) {
                return true;
            }
        }

        return false;
    }
}
