<?php

namespace App\Filament\GlobalSearch;

use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\GlobalSearch\Providers\Contracts\GlobalSearchProvider;
use Filament\GlobalSearch\Providers\DefaultGlobalSearchProvider;
use Illuminate\Support\Str;

/**
 * Pencarian global bawaan Filament ditambah kategori "Menu" di atas hasil data.
 * Item diambil dari navigasi panel yang sudah disaring permission dan saklar modul,
 * sehingga pengguna tidak pernah melihat menu yang tak boleh dibukanya.
 */
class NavigationAwareGlobalSearchProvider implements GlobalSearchProvider
{
    public const MIN_QUERY_LENGTH = 2;

    public function getResults(string $query): ?GlobalSearchResults
    {
        $needle = Str::lower(trim($query));
        $results = GlobalSearchResults::make();

        $menu = mb_strlen($needle) >= self::MIN_QUERY_LENGTH ? $this->matchingMenu($needle) : [];

        if ($menu !== []) {
            $results->category(__('pondok.navigation_search.category'), $menu);
        }

        $data = app(DefaultGlobalSearchProvider::class)->getResults($query);

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

                if ($url === null || ! Str::contains(Str::lower($label.' '.$groupLabel), $needle)) {
                    continue;
                }

                $matches[] = new GlobalSearchResult($label, $url, $groupLabel !== '' ? [__('pondok.navigation_search.group') => $groupLabel] : []);
            }
        }

        return $matches;
    }
}
