<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F043: pindahkan menu F037 dan tautan footer F036 ke model daftar tautan (data saja).
 *
 * - `portal.menu_items` (array) → `portal.navigation_primary` berisi item yang benar-benar tampil
 *   menurut logika gabungan F037 (disalin di sini, tidak memanggil kelas aplikasi);
 * - `portal.menu_show_register === false` → `portal.header_button = false`;
 * - `portal.footer_links` berisi → `portal.navigation_footer` (tautan lama + status PSB + verifikasi surat);
 * - kutipan footer → `portal.archived_footer_quote` (tidak dibaca aplikasi).
 *
 * Key lama dihapus, key baru yang sudah ada tidak ditimpa (idempoten). Ditulis dengan
 * `DB::table`, jadi JSON di-decode/encode sendiri; nilai rusak dianggap kosong.
 * `down()` lossy: tujuan yang tidak ada di model lama (kategori, URL di menu, grup) dibuang.
 */
return new class extends Migration
{
    private const LEGACY_KEYS = [
        'portal.menu_items',
        'portal.menu_show_register',
        'portal.footer_links',
        'portal.footer_quote_arabic',
        'portal.footer_quote_translation',
        'portal.footer_quote_source',
    ];

    private const NEW_KEYS = [
        'portal.navigation_primary',
        'portal.navigation_footer',
        'portal.header_button',
        'portal.archived_footer_quote',
    ];

    /** Item bawaan F037 → tipe tujuan F043. */
    private const BUILTIN_TYPES = [
        'home' => 'home',
        'news' => 'posts',
        'gallery' => 'albums',
        'videos' => 'videos',
        'psb_info' => 'psb.info',
    ];

    private const MAX_VISIBLE = 8;

    private const MAX_STORED = 60;

    private const MAX_LEGACY_FOOTER_LINKS = 8;

    public function up(): void
    {
        $pageIds = DB::table('pages')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $writes = [];

        $menu = $this->read('portal.menu_items');

        if (is_array($menu) && ! $this->has('portal.navigation_primary')) {
            $writes['portal.navigation_primary'] = $this->visibleMenu($menu, $pageIds);
        }

        if ($this->read('portal.menu_show_register') === false && ! $this->has('portal.header_button')) {
            $writes['portal.header_button'] = false;
        }

        $links = $this->read('portal.footer_links');

        if (is_array($links) && $links !== [] && ! $this->has('portal.navigation_footer')) {
            $writes['portal.navigation_footer'] = [
                ...$this->footerItems($links, $pageIds),
                ['type' => 'psb.status', 'label' => null],
                ['type' => 'letters.verify', 'label' => null],
            ];
        }

        $quote = [
            'arabic' => $this->text($this->read('portal.footer_quote_arabic')),
            'translation' => $this->text($this->read('portal.footer_quote_translation')),
            'source' => $this->text($this->read('portal.footer_quote_source')),
        ];

        if (array_filter($quote) !== [] && ! $this->has('portal.archived_footer_quote')) {
            $writes['portal.archived_footer_quote'] = $quote;
        }

        DB::transaction(function () use ($writes): void {
            foreach ($writes as $key => $value) {
                $this->write($key, $value);
            }

            DB::table('settings')->whereIn('key', self::LEGACY_KEYS)->delete();
        });
    }

    public function down(): void
    {
        $writes = [];
        $primary = $this->read('portal.navigation_primary');

        if (is_array($primary)) {
            $writes['portal.menu_items'] = $this->legacyMenu($primary);
        }

        $footer = $this->read('portal.navigation_footer');
        $links = is_array($footer) ? $this->legacyFooterLinks($footer) : [];

        if ($links !== []) {
            $writes['portal.footer_links'] = $links;
        }

        if ($this->read('portal.header_button') === false) {
            $writes['portal.menu_show_register'] = false;
        }

        $quote = $this->read('portal.archived_footer_quote');

        if (is_array($quote)) {
            foreach (['arabic', 'translation', 'source'] as $field) {
                $value = $this->text($quote[$field] ?? null);

                if ($value !== null) {
                    $writes["portal.footer_quote_{$field}"] = $value;
                }
            }
        }

        DB::transaction(function () use ($writes): void {
            foreach ($writes as $key => $value) {
                $this->write($key, $value);
            }

            DB::table('settings')->whereIn('key', self::NEW_KEYS)->delete();
        });
    }

    /**
     * Item yang tampil di header F037 (`GetPortalNavigation::mergedItems` lama): item rusak,
     * key tak dikenal, dan duplikat dibuang; maks. 8 tampil; bawaan
     * yang belum tercantum ditambahkan selama tampil < 8.
     *
     * @param  array<mixed>  $stored
     * @param  list<int>  $pageIds
     * @return list<array<string, mixed>>
     */
    private function visibleMenu(array $stored, array $pageIds): array
    {
        $items = [];
        $seen = [];

        foreach (array_slice(array_values($stored), 0, self::MAX_STORED) as $row) {
            $item = is_array($row) ? $this->legacyItem($row) : null;

            if ($item === null) {
                continue;
            }

            $id = $item['type'] === 'builtin' ? $item['key'] : 'page:'.$item['page_id'];

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $items[] = $item;
        }

        $visible = [];

        foreach ($items as $item) {
            if ($item['visible'] && count($visible) < self::MAX_VISIBLE) {
                $visible[] = $item;
            }
        }

        $listed = array_column(array_filter($items, fn (array $item): bool => $item['type'] === 'builtin'), 'key');

        foreach (array_diff(array_keys(self::BUILTIN_TYPES), $listed) as $key) {
            if (count($visible) < self::MAX_VISIBLE) {
                $visible[] = ['type' => 'builtin', 'key' => $key, 'visible' => true];
            }
        }

        // Halaman yang sudah tidak ada tetap memakan slot (seperti header lama), lalu dibuang.
        $visible = array_filter($visible, fn (array $item): bool => $item['type'] === 'builtin' || in_array($item['page_id'], $pageIds, true));

        return array_values(array_map(fn (array $item): array => $item['type'] === 'builtin'
            ? ['type' => self::BUILTIN_TYPES[$item['key']], 'label' => null]
            : ['type' => 'page', 'label' => $item['label'], 'page_id' => $item['page_id']], $visible));
    }

    /**
     * @param  array<mixed>  $row
     * @return ?array<string, mixed>
     */
    private function legacyItem(array $row): ?array
    {
        $visible = ($row['visible'] ?? false) === true;

        if (($row['type'] ?? null) === 'builtin') {
            $key = $row['key'] ?? null;

            return is_string($key) && isset(self::BUILTIN_TYPES[$key]) ? ['type' => 'builtin', 'key' => $key, 'visible' => $visible] : null;
        }

        if (($row['type'] ?? null) !== 'page') {
            return null;
        }

        $pageId = filter_var($row['page_id'] ?? null, FILTER_VALIDATE_INT);

        return $pageId === false || $pageId < 1 ? null : ['type' => 'page', 'page_id' => $pageId, 'label' => $this->text($row['label'] ?? null), 'visible' => $visible];
    }

    /**
     * @param  array<mixed>  $links
     * @param  list<int>  $pageIds
     * @return list<array<string, mixed>>
     */
    private function footerItems(array $links, array $pageIds): array
    {
        $items = [];

        foreach ($links as $link) {
            if (! is_array($link)) {
                continue;
            }

            $label = $this->text($link['label'] ?? null);

            if (array_key_exists('page_id', $link)) {
                $pageId = filter_var($link['page_id'], FILTER_VALIDATE_INT);

                if ($pageId !== false && in_array($pageId, $pageIds, true)) {
                    $items[] = ['type' => 'page', 'label' => $label, 'page_id' => $pageId];
                }

                continue;
            }

            $url = $link['url'] ?? null;

            if ($label !== null && $this->isSafeUrl($url)) {
                $items[] = ['type' => 'url', 'label' => $label, 'url' => $url];
            }
        }

        return $items;
    }

    /**
     * @param  array<mixed>  $primary
     * @return list<array<string, mixed>>
     */
    private function legacyMenu(array $primary): array
    {
        $flat = [];

        foreach ($primary as $item) {
            if (is_array($item) && ($item['type'] ?? null) === 'group') {
                array_push($flat, ...array_values(array_filter(is_array($item['children'] ?? null) ? $item['children'] : [], 'is_array')));
            } elseif (is_array($item)) {
                $flat[] = $item;
            }
        }

        $menu = [];
        $seen = [];
        $builtinKeys = array_flip(self::BUILTIN_TYPES);

        foreach ($flat as $item) {
            $type = $item['type'] ?? null;

            if ($type === 'page') {
                $pageId = filter_var($item['page_id'] ?? null, FILTER_VALIDATE_INT);
                $legacy = $pageId === false || $pageId < 1 ? null : ['type' => 'page', 'page_id' => $pageId, 'label' => $this->text($item['label'] ?? null), 'visible' => true];
                $id = "page:{$pageId}";
            } else {
                $key = is_string($type) ? ($builtinKeys[$type] ?? null) : null;
                $legacy = $key === null ? null : ['type' => 'builtin', 'key' => $key, 'visible' => true];
                $id = $key;
            }

            if ($legacy === null || isset($seen[$id]) || count($menu) >= self::MAX_VISIBLE) {
                continue;
            }

            $seen[$id] = true;
            $menu[] = $legacy;
        }

        return $menu;
    }

    /**
     * @param  array<mixed>  $footer
     * @return list<array<string, mixed>>
     */
    private function legacyFooterLinks(array $footer): array
    {
        $links = [];

        foreach ($footer as $item) {
            if (! is_array($item) || count($links) >= self::MAX_LEGACY_FOOTER_LINKS) {
                continue;
            }

            $label = $this->text($item['label'] ?? null);

            if (($item['type'] ?? null) === 'page') {
                $pageId = filter_var($item['page_id'] ?? null, FILTER_VALIDATE_INT);

                if ($pageId !== false && $pageId > 0) {
                    $links[] = ['label' => $label, 'page_id' => $pageId];
                }
            } elseif (($item['type'] ?? null) === 'url' && $label !== null && $this->isSafeUrl($item['url'] ?? null)) {
                $links[] = ['label' => $label, 'url' => $item['url']];
            }
        }

        return $links;
    }

    private function read(string $key): mixed
    {
        $value = DB::table('settings')->where('key', $key)->value('value');

        return is_string($value) ? json_decode($value, true) : null;
    }

    private function has(string $key): bool
    {
        return DB::table('settings')->where('key', $key)->exists();
    }

    private function write(string $key, mixed $value): void
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($this->has($key)) {
            DB::table('settings')->where('key', $key)->update(['value' => $encoded, 'updated_at' => now()]);

            return;
        }

        DB::table('settings')->insert(['key' => $key, 'value' => $encoded, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * URL absolut http(s) atau path internal aman (aturan `ValidatesPublicUrls`, disalin).
     */
    private function isSafeUrl(mixed $url): bool
    {
        if (! is_string($url) || $url === '' || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return false;
        }

        if ($url[0] === '/') {
            return ! in_array($url[1] ?? '', ['/', '\\'], true) && ! str_contains($url, '\\');
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            && (string) parse_url($url, PHP_URL_HOST) !== '';
    }
};
