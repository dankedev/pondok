<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\ValidatesPublicUrls;
use App\Models\Album;
use App\Models\Category;
use App\Models\Post;
use App\Models\Setting;
use App\Models\StaticPage;
use App\Models\Video;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Navigasi portal (F043), dihitung sekali per render layout: menu utama (tautan dan grup
 * submenu satu tingkat), tombol header, dan navigasi footer.
 *
 * Item tersimpan `{type, label: ?string, page_id?, category_id?, url?, children?}` di
 * `portal.navigation_primary`, `portal.navigation_footer`, dan `portal.header_button`
 * (dibaca bersama profil lewat `GetSchoolProfile::KEYS`). Key tidak ada = bawaan; daftar
 * kosong = tanpa item; tombol `false` = tanpa tombol. Item rusak dibuang per item saat baca,
 * dan tujuan yang tidak tersedia (modul mati, halaman draf, daftar kosong, route belum ada)
 * tidak dirender. Query: halaman, kategori, dan satu cek isi daftar, masing-masing hanya bila dipakai.
 */
class GetPortalNavigation
{
    use ValidatesPublicUrls;

    public const SETTING_KEYS = ['portal.navigation_primary', 'portal.navigation_footer', 'portal.header_button'];

    /** Tipe tujuan; `group` hanya di akar menu utama. */
    public const TYPES = ['home', 'page', 'posts', 'category', 'albums', 'videos', 'psb.info', 'psb.register', 'psb.status', 'letters.verify', 'url', 'group'];

    public const MAX_PRIMARY = 8;

    public const MAX_CHILDREN = 8;

    public const MAX_FOOTER = 12;

    /** Batas label menu utama, anak grup, dan tombol header. */
    public const LABEL_MAX_MENU = 30;

    public const LABEL_MAX_FOOTER = 60;

    public const URL_MAX_LENGTH = 255;

    /** Menu bawaan (sama dengan header sebelum F037); halaman dicari per slug. */
    public const DEFAULT_PRIMARY = ['home', 'page:profil', 'posts', 'albums', 'videos', 'psb.info', 'page:kontak'];

    public const DEFAULT_FOOTER = ['psb.status', 'letters.verify'];

    public const DEFAULT_HEADER_BUTTON = 'psb.register';

    /** Label bawaan per tipe sistem (`pondok.portal.nav.*`). */
    private const LABEL_KEYS = [
        'home' => 'home',
        'posts' => 'news',
        'albums' => 'gallery',
        'videos' => 'videos',
        'psb.info' => 'psb_info',
        'psb.register' => 'register',
        'psb.status' => 'psb_status',
        'letters.verify' => 'letters_verify',
    ];

    /** Tipe yang butuh cek "daftar tidak kosong". */
    private const LIST_TYPES = ['posts', 'albums', 'videos'];

    private bool $contentEnabled = false;

    /** @var Collection<int, StaticPage> */
    private Collection $pages;

    /** @var Collection<int, Category> */
    private Collection $categories;

    /** @var array<string, bool> */
    private array $lists = [];

    /**
     * @param  array<string, mixed>  $school  keluaran `GetSchoolProfile`
     * @return array{primary: list<array<string, mixed>>, headerButton: ?array{label: string, url: string, active: bool}, footer: list<array{label: string, url: string, active: bool}>}
     */
    public function handle(array $school): array
    {
        $stored = Setting::getMany(self::SETTING_KEYS);
        $this->contentEnabled = Setting::moduleEnabled('konten');

        $primaryValue = $stored['portal.navigation_primary'];
        $primary = $primaryValue === null ? $this->defaultPrimary() : self::parseItems($primaryValue, true);
        $footer = $stored['portal.navigation_footer'] === null
            ? array_map(fn (string $type): array => ['type' => $type, 'label' => null], self::DEFAULT_FOOTER)
            : self::parseItems($stored['portal.navigation_footer'], false);
        $buttonValue = $stored['portal.header_button'];
        $button = $buttonValue === null ? ['type' => self::DEFAULT_HEADER_BUTTON, 'label' => null] : (is_array($buttonValue) ? self::parseItem($buttonValue, false) : null);

        $this->loadReferences([...$primary, ...$footer, ...($button === null ? [] : [$button])], $primaryValue === null, GetSchoolProfile::hasContactData($school));

        return [
            'primary' => $this->markVisionUnderProfile($this->links($primary, self::LABEL_MAX_MENU)),
            'headerButton' => $button === null ? null : $this->link($button, self::LABEL_MAX_MENU),
            'footer' => $this->links($footer, null),
        ];
    }

    /**
     * Resolusi tautan untuk Item yang sudah di-parse (`parseItem`) di luar menu, mis. tombol
     * blok beranda (F045): semua daftar diselesaikan dengan satu batch query yang sama dengan
     * menu, dan tujuan yang tidak tersedia dibuang. Kunci `$lists` dipertahankan.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, list<array<string, mixed>>>  $lists
     * @return array<TKey, list<array<string, mixed>>>
     */
    public function resolveItems(array $lists, ?int $labelMax): array
    {
        $this->contentEnabled = Setting::moduleEnabled('konten');
        $this->loadReferences(array_merge(...array_values($lists)), false, GetSchoolProfile::hasContactData(app(GetSchoolProfile::class)->handle()));

        return array_map(fn (array $items): array => $this->links($items, $labelMax), $lists);
    }

    /**
     * Menu bawaan sebagai item tersimpan, untuk materialisasi (form Navigasi, aksi Tambahkan):
     * hanya halaman yang ada (draf boleh).
     *
     * @return list<array<string, mixed>>
     */
    public static function materializedDefaultPrimary(): array
    {
        $slugs = self::defaultPageSlugs();
        $ids = StaticPage::query()->whereIn('slug', $slugs)->pluck('id', 'slug');
        $items = [];

        foreach (self::DEFAULT_PRIMARY as $entry) {
            if (! str_starts_with($entry, 'page:')) {
                $items[] = ['type' => $entry, 'label' => null];
            } elseif ($ids->has(substr($entry, 5))) {
                $items[] = ['type' => 'page', 'label' => null, 'page_id' => (int) $ids->get(substr($entry, 5))];
            }
        }

        return $items;
    }

    /**
     * Item tersimpan yang terbaca; baris rusak, tipe tak dikenal, URL tak aman, dan
     * grup di luar akar dibuang per item (tanpa exception).
     *
     * @return list<array<string, mixed>>
     */
    public static function parseItems(mixed $value, bool $allowGroups): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $row) {
            $item = is_array($row) ? self::parseItem($row, $allowGroups) : null;

            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param  array<mixed>  $row
     * @return ?array<string, mixed>
     */
    public static function parseItem(array $row, bool $allowGroup): ?array
    {
        $type = $row['type'] ?? null;

        if (! is_string($type) || ! in_array($type, self::TYPES, true)) {
            return null;
        }

        $label = is_string($row['label'] ?? null) && trim($row['label']) !== '' ? trim($row['label']) : null;
        $item = ['type' => $type, 'label' => $label];

        return match ($type) {
            'page', 'category' => ($id = self::positiveId($row[$type.'_id'] ?? null)) === null ? null : [...$item, $type.'_id' => $id],
            'url' => $label !== null && self::isSafeTarget($row['url'] ?? null) ? [...$item, 'url' => $row['url']] : null,
            'group' => $allowGroup && $label !== null ? (($children = self::parseItems($row['children'] ?? null, false)) === [] ? null : [...$item, 'children' => $children]) : null,
            default => $item,
        };
    }

    /**
     * URL tujuan tipe `url`: absolut http(s) atau path internal aman, maks. 255 karakter.
     */
    public static function isSafeTarget(mixed $url): bool
    {
        $checker = new self;

        return is_string($url) && mb_strlen($url) <= self::URL_MAX_LENGTH && ($checker->isSafeAbsoluteUrl($url) || $checker->isSafeInternalPath($url));
    }

    private static function positiveId(mixed $value): ?int
    {
        $id = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;

        return $id === false || $id < 1 ? null : $id;
    }

    /**
     * @return list<string>
     */
    private static function defaultPageSlugs(): array
    {
        return array_values(array_map(fn (string $entry): string => substr($entry, 5), array_filter(self::DEFAULT_PRIMARY, fn (string $entry): bool => str_starts_with($entry, 'page:'))));
    }

    /**
     * Menu bawaan untuk baca portal; halaman di-resolve per slug di `loadReferences`.
     *
     * @return list<array<string, mixed>>
     */
    private function defaultPrimary(): array
    {
        return array_map(fn (string $entry): array => str_starts_with($entry, 'page:')
            ? ['type' => 'page', 'label' => null, 'slug' => substr($entry, 5)]
            : ['type' => $entry, 'label' => null], self::DEFAULT_PRIMARY);
    }

    /**
     * Satu query halaman terbit, satu query kategori berita bertautan berita terbit, dan satu
     * query cek isi daftar; masing-masing hanya bila modul konten aktif dan tujuannya dipakai.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function loadReferences(array $items, bool $defaultPages, bool $hasContact): void
    {
        $this->pages = collect();
        $this->categories = collect();
        $this->lists = [];

        if (! $this->contentEnabled) {
            return;
        }

        $flat = [];

        foreach ($items as $item) {
            array_push($flat, $item, ...($item['children'] ?? []));
        }

        $pageIds = array_values(array_unique(array_column($flat, 'page_id')));
        $slugs = $defaultPages ? self::defaultPageSlugs() : [];

        if ($pageIds !== [] || $slugs !== []) {
            $this->pages = StaticPage::query()
                ->published()
                ->where(fn ($query) => $query->whereIn('id', $pageIds)->orWhereIn('slug', $slugs))
                ->get(['id', 'slug', 'title'])
                ->reject(fn (StaticPage $page): bool => $page->slug === 'kontak' && ! $hasContact)
                ->keyBy('id');
        }

        $categoryIds = array_values(array_unique(array_column($flat, 'category_id')));

        if ($categoryIds !== []) {
            $this->categories = Category::query()
                ->ofType(Category::TYPE_POST)
                ->whereIn('id', $categoryIds)
                ->whereHas('posts', fn ($query) => $query->published())
                ->get(['id', 'slug', 'name'])
                ->keyBy('id');
        }

        $listTypes = array_values(array_intersect(self::LIST_TYPES, array_column($flat, 'type')));

        if ($listTypes !== []) {
            $queries = [
                'posts' => fn () => Post::query()->published(),
                'albums' => fn () => Album::query()->has('photos'),
                'videos' => fn () => Video::query(),
            ];
            $select = DB::query();

            foreach ($listTypes as $type) {
                $select->selectSub($queries[$type]()->selectRaw('1')->limit(1), $type);
            }

            $row = (array) $select->first();

            foreach ($listTypes as $type) {
                $this->lists[$type] = ($row[$type] ?? null) !== null;
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function links(array $items, ?int $labelMax): array
    {
        return array_values(array_filter(array_map(fn (array $item): ?array => $this->link($item, $labelMax), $items)));
    }

    /**
     * Tautan tersedia, atau `null`. `$labelMax` = batas fallback judul halaman (null = penuh).
     *
     * @param  array<string, mixed>  $item
     * @return ?array<string, mixed>
     */
    private function link(array $item, ?int $labelMax): ?array
    {
        $type = $item['type'];

        if ($type === 'group') {
            $children = $this->links($item['children'], $labelMax);

            return $children === [] ? null : [
                'label' => $item['label'],
                'children' => $children,
                'active' => in_array(true, array_column($children, 'active'), true),
            ];
        }

        $target = $this->target($item, $labelMax);

        return $target === null ? null : ['label' => $item['label'] ?? $target['label'], 'url' => $target['url'], 'active' => $target['active']];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return ?array{label: string, url: string, active: bool}
     */
    private function target(array $item, ?int $labelMax): ?array
    {
        $type = $item['type'];

        return match ($type) {
            'home' => $this->system($type, 'portal.home', 'portal.home', true),
            'posts' => $this->system($type, 'portal.posts.index', 'portal.posts.*', $this->lists['posts'] ?? false),
            'albums' => $this->system($type, 'portal.albums.index', 'portal.albums.*', $this->lists['albums'] ?? false),
            'videos' => $this->system($type, 'portal.videos.index', 'portal.videos.*', $this->lists['videos'] ?? false),
            'psb.info', 'psb.register', 'psb.status' => $this->system($type, $type, $type, Setting::moduleEnabled('psb')),
            'letters.verify' => $this->system($type, 'dokumen.letters.verify-form', 'dokumen.letters.verify-form', Setting::moduleEnabled('dokumen')),
            'page' => $this->pageTarget($item, $labelMax),
            'category' => $this->categoryTarget($item['category_id']),
            'url' => ['label' => $item['label'], 'url' => $item['url'], 'active' => false],
            default => null,
        };
    }

    /**
     * @return ?array{label: string, url: string, active: bool}
     */
    private function system(string $type, string $routeName, string $activePattern, bool $available): ?array
    {
        if (! $available || ! Route::has($routeName)) {
            return null;
        }

        return [
            'label' => __('pondok.portal.nav.'.self::LABEL_KEYS[$type]),
            'url' => route($routeName),
            'active' => request()->routeIs($activePattern),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return ?array{label: string, url: string, active: bool}
     */
    private function pageTarget(array $item, ?int $labelMax): ?array
    {
        $page = isset($item['slug'])
            ? $this->pages->firstWhere('slug', $item['slug'])
            : $this->pages->get($item['page_id']);

        if ($page === null) {
            return null;
        }

        return [
            'label' => $labelMax === null ? $page->title : Str::limit($page->title, $labelMax),
            'url' => route('portal.pages.show', $page->slug),
            'active' => request()->routeIs('portal.pages.show') && request()->route('page') === $page->slug,
        ];
    }

    /**
     * @return ?array{label: string, url: string, active: bool}
     */
    private function categoryTarget(int $id): ?array
    {
        $category = $this->categories->get($id);

        if ($category === null) {
            return null;
        }

        return [
            'label' => $category->name,
            'url' => route('portal.posts.index', ['kategori' => $category->slug]),
            'active' => request()->routeIs('portal.posts.index') && request()->query('kategori') === $category->slug,
        ];
    }

    /**
     * Seperti header sebelum F037: di halaman Visi & misi yang tidak punya item sendiri,
     * item Profil (di akar atau di grup) yang ditandai aktif.
     *
     * @param  list<array<string, mixed>>  $links
     * @return list<array<string, mixed>>
     */
    private function markVisionUnderProfile(array $links): array
    {
        if (! request()->routeIs('portal.pages.show') || request()->route('page') !== 'visi-misi' || in_array(true, array_column($links, 'active'), true)) {
            return $links;
        }

        $profileUrl = route('portal.pages.show', 'profil');
        $mark = fn (array $link): array => ($link['url'] ?? null) === $profileUrl ? [...$link, 'active' => true] : $link;

        return array_map(function (array $link) use ($mark): array {
            if (! isset($link['children'])) {
                return $mark($link);
            }

            $children = array_map($mark, $link['children']);

            return [...$link, 'children' => $children, 'active' => in_array(true, array_column($children, 'active'), true)];
        }, $links);
    }
}
