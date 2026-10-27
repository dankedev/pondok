<?php

namespace App\Filament\GlobalSearch;

use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\GlobalSearch\Providers\Contracts\GlobalSearchProvider;
use Filament\GlobalSearch\Providers\DefaultGlobalSearchProvider;
use Illuminate\Support\Str;

/**
 * Pencarian global bawaan Filament ditambah kategori "Menu" dan "Aksi" di atas hasil data.
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

        $long = mb_strlen($needle) >= self::MIN_QUERY_LENGTH;
        $menu = $long ? $this->matchingMenu($needle) : [];
        $actions = $long ? $this->matchingActions($needle) : [];

        if ($menu !== []) {
            $results->category(__('pondok.navigation_search.category'), $menu);
        }

        if ($actions !== []) {
            $results->category(__('pondok.navigation_search.actions'), $actions);
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

            if (Str::contains(Str::lower($label), $needle)) {
                $matches[] = new GlobalSearchResult($label, $resource::getUrl('create'));
            }
        }

        return $matches;
    }
}
