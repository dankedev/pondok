<?php

namespace App\Filament\GlobalSearch;

use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\GlobalSearch\Providers\Contracts\GlobalSearchProvider;
use Filament\GlobalSearch\Providers\DefaultGlobalSearchProvider;
use Illuminate\Support\Str;

/**
 * Sumber hasil palet perintah (F092): kategori "Aksi" dan "Menu" di atas hasil data pencarian
 * global bawaan Filament. Kata kunci kosong menampilkan semua aksi dan menu.
 * Menu diambil dari navigasi panel, aksi ("Tambah <model>") dari resource yang punya
 * halaman create dan lolos `canCreate()`. Keduanya sudah disaring permission dan saklar
 * modul, sehingga pengguna tidak pernah melihat yang tak boleh dibukanya.
 */
class NavigationAwareGlobalSearchProvider implements GlobalSearchProvider
{
    public const MIN_QUERY_LENGTH = 2;

    public function getResults(string $query): ?GlobalSearchResults
    {
        $needle = Str::lower(trim($query));
        $results = GlobalSearchResults::make();

        $long = $needle === '' || mb_strlen($needle) >= self::MIN_QUERY_LENGTH;
        $menu = $long ? $this->matchingMenu($needle) : [];
        $actions = $long ? $this->matchingActions($needle) : [];

        if ($menu !== []) {
            $results->category(__('pondok.navigation_search.category'), $menu);
        }

        if ($actions !== []) {
            $results->category(__('pondok.navigation_search.actions'), $actions);
        }

        $data = mb_strlen($needle) >= self::MIN_QUERY_LENGTH ? app(DefaultGlobalSearchProvider::class)->getResults($query) : null;

        foreach ($data?->getCategories() ?? [] as $name => $items) {
            $results->category($name, $items);
        }

        return $results;
    }

    /**
     * @return list<GlobalSearchResult>
     */
    private function matchingMenu(string $needle): array
    {
        $matches = [];

        foreach (Filament::getNavigation() as $group) {
            $groupLabel = (string) $group->getLabel();

            foreach ($group->getItems() as $item) {
                $label = (string) $item->getLabel();
                $url = $item->getUrl();

                if ($url === null || ! $this->matches($label.' '.$groupLabel, $needle)) {
                    continue;
                }

                $matches[] = new GlobalSearchResult($label, $url, $groupLabel !== '' ? [__('pondok.navigation_search.group') => $groupLabel] : []);
            }
        }

        return $matches;
    }

    /**
     * @return list<GlobalSearchResult>
     */
    private function matchingActions(string $needle): array
    {
        $matches = [];

        foreach (Filament::getResources() as $resource) {
            if (! $resource::hasPage('create') || ! $resource::canCreate()) {
                continue;
            }

            $label = __('pondok.navigation_search.create', ['model' => Str::lower($resource::getModelLabel())]);

            if ($this->matches($label, $needle)) {
                $matches[] = new GlobalSearchResult($label, $resource::getUrl('create'));
            }
        }

        return $matches;
    }

    private function matches(string $haystack, string $needle): bool
    {
        return $needle === '' || Str::contains(Str::lower($haystack), $needle);
    }
}
