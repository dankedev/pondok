<?php

namespace App\Actions\Konten;

use App\Models\Setting;
use App\Models\StaticPage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Navigasi portal (F037), dihitung sekali per render layout: item menu header,
 * tombol Daftar, dan tautan cepat footer yang sudah di-resolve.
 *
 * Menu tersimpan di `portal.menu_items` (`null` = urutan header bawaan). Halaman
 * dirujuk lewat `page_id` dan diambil dengan satu query `pages` (hanya yang terbit)
 * untuk menu dan footer sekaligus; tidak ada query bila modul konten mati atau tidak
 * ada rujukan halaman. Halaman `kontak` tidak tersedia tanpa data kontak.
 */
class GetPortalNavigation
{
    /** Item bawaan menurut urutan katalog. */
    public const BUILTIN_KEYS = ['home', 'news', 'gallery', 'videos', 'psb_info'];

    /** Batas item `visible` (di luar Masuk/Daftar). */
    public const MAX_VISIBLE = 8;

    /** Entri tersimpan yang dibaca: 5 bawaan + batas halaman. */
    public const MAX_STORED = 60;

    public const LABEL_MAX_LENGTH = 30;

    /** Menu bawaan saat `portal.menu_items` null: sama dengan header sebelum F037. */
    private const DEFAULT_ORDER = ['home', 'page:profil', 'news', 'gallery', 'videos', 'psb_info', 'page:kontak'];

    /**
     * @param  array<string, mixed>  $school  keluaran `GetSchoolProfile`
     * @return array{items: list<array{label: string, url: string, active: bool}>, registerUrl: ?string, footerLinks: list<array{label: string, url: string}>}
     */
    public function handle(array $school): array
    {
        $contentEnabled = Setting::moduleEnabled('konten');
        $psbEnabled = Setting::moduleEnabled('psb');
        $stored = Setting::get('portal.menu_items');
        $hasContact = GetSchoolProfile::hasContactData($school);

        $pages = $contentEnabled ? $this->publishedPages($stored, $school['footer']['links']) : collect();
        $available = $pages->reject(fn (StaticPage $page): bool => $page->slug === 'kontak' && ! $hasContact);

        $items = [];

        foreach ($this->mergedItems($stored, $pages->pluck('id', 'slug')->all()) as $item) {
            if (! $item['visible']) {
                continue;
            }

            $link = $item['type'] === 'builtin'
                ? $this->builtinLink($item['key'], $contentEnabled, $psbEnabled)
                : $this->pageLink($available->get($item['page_id']), $item['label']);

            if ($link !== null) {
                $items[] = $link;
            }
        }

        $items = $this->markVisionUnderProfile($items);
        $showRegister = Setting::get('portal.menu_show_register') !== false;

        return [
            'items' => $items,
            'registerUrl' => $showRegister && $psbEnabled && Route::has('psb.register') ? route('psb.register') : null,
            'footerLinks' => $this->footerLinks($school['footer']['links'], $available),
        ];
    }

    /**
     * Gabungan menu tersimpan dengan katalog (dipakai portal dan editor Menu):
     * item rusak, key tak dikenal, halaman di luar `$pageIds` (bila diberikan),
     * dan duplikat dibuang; maks 8 `visible`; item bawaan yang belum ada ditambahkan
     * di akhir, `visible` hanya bila jumlah `visible` masih < 8.
     *
     * @param  array<string, int>  $systemPageIds  slug → id halaman sistem (untuk menu bawaan)
     * @param  list<int>|null  $pageIds  halaman yang ada; null = tidak diperiksa
     * @return list<array{type: 'builtin', key: string, visible: bool}|array{type: 'page', page_id: int, label: ?string, visible: bool}>
     */
    public function mergedItems(mixed $stored, array $systemPageIds, ?array $pageIds = null): array
    {
        $items = is_array($stored) ? $this->storedItems($stored, $pageIds) : $this->defaultItems($systemPageIds);
        $visible = 0;

        foreach ($items as $index => $item) {
            if ($item['visible'] && ++$visible > self::MAX_VISIBLE) {
                $items[$index]['visible'] = false;
            }
        }

        $visible = min($visible, self::MAX_VISIBLE);
        $listed = array_column(array_filter($items, fn (array $item): bool => $item['type'] === 'builtin'), 'key');

        foreach (array_diff(self::BUILTIN_KEYS, $listed) as $key) {
            $show = $visible < self::MAX_VISIBLE;
            $visible += $show ? 1 : 0;
            $items[] = ['type' => 'builtin', 'key' => $key, 'visible' => $show];
        }

        return $items;
    }

    /**
     * @param  array<mixed>  $stored
     * @param  list<int>|null  $pageIds
     * @return list<array<string, mixed>>
     */
    private function storedItems(array $stored, ?array $pageIds): array
    {
        $items = [];
        $seen = [];

        foreach (array_slice(array_values($stored), 0, self::MAX_STORED) as $row) {
            $item = is_array($row) ? $this->storedItem($row) : null;

            if ($item === null) {
                continue;
            }

            $id = $item['type'] === 'builtin' ? $item['key'] : 'page:'.$item['page_id'];

            if (isset($seen[$id]) || ($item['type'] === 'page' && $pageIds !== null && ! in_array($item['page_id'], $pageIds, true))) {
                continue;
            }

            $seen[$id] = true;
            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param  array<mixed>  $row
     * @return ?array<string, mixed>
     */
    private function storedItem(array $row): ?array
    {
        $visible = ($row['visible'] ?? false) === true;

        if (($row['type'] ?? null) === 'builtin') {
            $key = $row['key'] ?? null;

            return in_array($key, self::BUILTIN_KEYS, true) ? ['type' => 'builtin', 'key' => $key, 'visible' => $visible] : null;
        }

        if (($row['type'] ?? null) !== 'page') {
            return null;
        }

        $pageId = filter_var($row['page_id'] ?? null, FILTER_VALIDATE_INT);
        $label = is_string($row['label'] ?? null) && trim($row['label']) !== '' ? $row['label'] : null;

        return $pageId === false || $pageId < 1 ? null : ['type' => 'page', 'page_id' => $pageId, 'label' => $label, 'visible' => $visible];
    }

    /**
     * @param  array<string, int>  $systemPageIds
     * @return list<array<string, mixed>>
     */
    private function defaultItems(array $systemPageIds): array
    {
        $items = [];

        foreach (self::DEFAULT_ORDER as $entry) {
            if (! str_starts_with($entry, 'page:')) {
                $items[] = ['type' => 'builtin', 'key' => $entry, 'visible' => true];
            } elseif (isset($systemPageIds[substr($entry, 5)])) {
                $items[] = ['type' => 'page', 'page_id' => $systemPageIds[substr($entry, 5)], 'label' => null, 'visible' => true];
            }
        }

        return $items;
    }

    /**
     * Seperti header sebelum F037: di halaman Visi & misi yang tidak punya item sendiri,
     * item Profil yang ditandai aktif.
     *
     * @param  list<array{label: string, url: string, active: bool}>  $items
     * @return list<array{label: string, url: string, active: bool}>
     */
    private function markVisionUnderProfile(array $items): array
    {
        if (! request()->routeIs('portal.pages.show') || request()->route('page') !== 'visi-misi' || in_array(true, array_column($items, 'active'), true)) {
            return $items;
        }

        $profileUrl = route('portal.pages.show', 'profil');

        return array_map(fn (array $item): array => $item['url'] === $profileUrl ? [...$item, 'active' => true] : $item, $items);
    }

    /**
     * Satu query untuk semua halaman terbit yang dirujuk menu dan footer.
     *
     * @param  list<array<string, mixed>>  $footerLinks
     * @return Collection<int, StaticPage>
     */
    private function publishedPages(mixed $stored, array $footerLinks): Collection
    {
        $ids = array_filter(array_column($footerLinks, 'page_id'));
        $slugs = [];

        if (is_array($stored)) {
            foreach ($this->storedItems($stored, null) as $item) {
                if ($item['type'] === 'page' && $item['visible']) {
                    $ids[] = $item['page_id'];
                }
            }
        } else {
            $slugs = ['profil', 'kontak'];
        }

        if ($ids === [] && $slugs === []) {
            return collect();
        }

        return StaticPage::query()
            ->published()
            ->where(fn ($query) => $query->whereIn('id', array_values(array_unique($ids)))->orWhereIn('slug', $slugs))
            ->get(['id', 'slug', 'title'])
            ->keyBy('id');
    }

    /**
     * @return ?array{label: string, url: string, active: bool}
     */
    private function builtinLink(string $key, bool $contentEnabled, bool $psbEnabled): ?array
    {
        [$routeName, $activePattern] = match ($key) {
            'home' => ['portal.home', 'portal.home'],
            'news' => ['portal.posts.index', 'portal.posts.*'],
            'gallery' => ['portal.albums.index', 'portal.albums.*'],
            'videos' => ['portal.videos.index', 'portal.videos.*'],
            'psb_info' => ['psb.info', 'psb.info'],
        };

        $shown = match ($key) {
            'home' => true,
            'psb_info' => $psbEnabled && Route::has('psb.info'),
            default => $contentEnabled,
        };

        return $shown ? [
            'label' => __("pondok.portal.nav.{$key}"),
            'url' => route($routeName),
            'active' => request()->routeIs($activePattern),
        ] : null;
    }

    /**
     * @return ?array{label: string, url: string, active: bool}
     */
    private function pageLink(?StaticPage $page, ?string $label): ?array
    {
        if ($page === null) {
            return null;
        }

        return [
            'label' => $label ?? Str::limit($page->title, self::LABEL_MAX_LENGTH),
            'url' => route('portal.pages.show', $page->slug),
            'active' => request()->routeIs('portal.pages.show') && request()->route('page') === $page->slug,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $links
     * @param  Collection<int, StaticPage>  $available
     * @return list<array{label: string, url: string}>
     */
    private function footerLinks(array $links, Collection $available): array
    {
        $resolved = [];

        foreach ($links as $link) {
            if (! isset($link['page_id'])) {
                $resolved[] = ['label' => $link['label'], 'url' => $link['url']];

                continue;
            }

            $page = $available->get($link['page_id']);

            if ($page !== null) {
                $resolved[] = ['label' => $link['label'] ?? $page->title, 'url' => route('portal.pages.show', $page->slug)];
            }
        }

        return $resolved;
    }
}
