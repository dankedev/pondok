<?php

namespace Database\Seeders\Demo;

use App\Actions\Konten\AddAlbumPhotos;
use App\Actions\Konten\CreateStaticPage;
use App\Actions\Konten\GetPortalNavigation;
use App\Actions\Konten\GetSchoolProfile;
use App\Actions\Konten\SaveAlbum;
use App\Actions\Konten\SaveCategory;
use App\Actions\Konten\SavePost;
use App\Actions\Konten\UpdatePortalFooter;
use App\Actions\Konten\UpdatePortalHome;
use App\Actions\Konten\UpdatePortalNavigation;
use App\Actions\Konten\UpdateSchoolContacts;
use App\Actions\Konten\UpdateSchoolProfile;
use App\Actions\Konten\UpdateStaticPage;
use App\Models\Album;
use App\Models\Category;
use App\Models\Post;
use App\Models\Setting;
use App\Models\StaticPage;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Profil sekolah, halaman statis, kategori, berita, dan album demo. Video sengaja tidak dibuat: ID YouTube milik orang lain tidak dipakai.
 */
final class DemoContent
{
    public function __construct(private readonly DemoContext $context) {}

    /**
     * Profil sekolah dan halaman statis; dijalankan sebelum surat diterbitkan.
     */
    public function profile(): void
    {
        if (blank(Setting::get('school.address'))) {
            $this->seedSchoolIdentity();
        }

        // Halaman awal (dari StaticPageSeeder) diisi bila masih kosong; halaman demo lain dibuat bila belum ada (F037).
        // Halaman Kontak demo lama yang mengetik ulang kontak diganti isi baru (F044).
        foreach (DemoData::pages() as $slug => $page) {
            $model = StaticPage::query()->where('slug', $slug)->first();

            if ($model === null) {
                $this->ensureAllowedContent($page['title'].' '.$page['body']);
                app(CreateStaticPage::class)->handle([...$page, 'slug' => $slug]);
            } elseif ($model !== null && (blank($model->body) || ($slug === 'kontak' && $this->isOldDemoContactPage((string) $model->body)))) {
                $this->ensureAllowedContent($page['title'].' '.$page['body']);
                app(UpdateStaticPage::class)->handle($model, $page);
            }
        }
    }

    /**
     * Nama dan slogan lewat `UpdateSchoolProfile`; alamat dan email lewat `UpdateSchoolContacts`
     * (F044), dengan kontak, media sosial, dan tautan peta yang sudah tersimpan dipertahankan.
     */
    private function seedSchoolIdentity(): void
    {
        $actor = $this->context->user('super-admin');
        $school = DemoData::SCHOOL;

        app(UpdateSchoolProfile::class)->handle(collect($school)->only(['name', 'tagline'])->all(), $actor);

        $profile = app(GetSchoolProfile::class)->handle();

        app(UpdateSchoolContacts::class)->handle([
            'address' => $school['address'],
            'email' => $profile['email'] ?? $school['email'],
            'map_url' => $profile['map_url'],
            'contacts' => $profile['contacts'],
            'social_links' => $profile['social_links'],
        ], $actor);
    }

    /**
     * Isi halaman Kontak demo versi lama: memuat email atau nomor demo persis.
     * Halaman Kontak tulisan admin tidak memuat nilai fiktif ini, jadi tidak disentuh.
     * Nilai dicocokkan utuh: nomor atau email yang hanya mirip (lebih panjang) tidak dianggap sama.
     */
    private function isOldDemoContactPage(string $body): bool
    {
        return collect(DemoData::OLD_CONTACT_PAGE_VALUES)->contains(
            fn (string $value): bool => preg_match('/(?<![\w.@-])'.preg_quote($value, '/').'(?![\w@-])/u', $body) === 1,
        );
    }

    public function run(): void
    {
        $this->seedPosts($this->seedCategories());
        $this->seedAlbums();
        $this->seedInstitutionProfile();
    }

    /**
     * Profil lembaga demo (F036) lewat Action panel. Tiap bagian hanya diisi bila
     * masih kosong, sehingga seed ulang tidak mengubah isian atau menambah berkas logo.
     */
    private function seedInstitutionProfile(): void
    {
        $actor = $this->context->user('super-admin');
        $demo = DemoData::INSTITUTION;
        $profile = app(GetSchoolProfile::class)->handle();

        $identity = collect($profile)->only(['name', 'short_name', 'arabic_name', 'tagline'])->all();
        $identity['short_name'] ??= $demo['short_name'];
        $identity['arabic_name'] ??= $demo['arabic_name'];

        foreach ($demo['logos'] as $field => $source) {
            $path = base_path($source);

            if ($profile[$field.'_path'] === null && is_file($path)) {
                $identity[$field] = new UploadedFile($path, basename($path), null, null, true);
            }
        }

        app(UpdateSchoolProfile::class)->handle($identity, $actor);

        if ($profile['contacts'] === [] && $profile['social_links'] === []) {
            app(UpdateSchoolContacts::class)->handle([
                'address' => $profile['address'],
                'email' => $profile['email'],
                'map_url' => $profile['map_url'],
                'contacts' => $demo['contacts'],
                'social_links' => $demo['social_links'],
            ], $actor);
        }

        if (Setting::get(UpdatePortalHome::KEY) === null) {
            $this->seedHomeBlocks();
        }

        if (Setting::get(UpdatePortalFooter::KEY) === null) {
            $this->seedFooterWidgets();
        }

        if (Setting::query()->whereIn('key', GetPortalNavigation::SETTING_KEYS)->doesntExist()) {
            $this->seedNavigation();
        }
    }

    /**
     * Navigasi demo (F043) lewat Action panel: menu bergrup dan tombol header.
     */
    private function seedNavigation(): void
    {
        $navigation = DemoData::NAVIGATION;
        $this->ensureAllowedContent(json_encode($navigation, JSON_UNESCAPED_UNICODE));

        $primary = [];

        foreach ($navigation['primary'] as $entry) {
            if (is_string($entry)) {
                $primary[] = $this->linkItem($entry);
            } elseif (($children = $this->linkItems($entry['children'])) !== []) {
                $primary[] = ['type' => 'group', 'label' => $entry['label'], 'children' => $children];
            }
        }

        app(UpdatePortalNavigation::class)->handle([
            'primary' => array_values(array_filter($primary)),
            'header_button' => $navigation['header_button'],
        ], $this->context->user('super-admin'));
    }

    /**
     * Beranda demo (F045) lewat `UpdatePortalHome`, hanya bila beranda belum pernah disimpan.
     * Tombol ke halaman yang tidak ada dan album hero yang tidak ada dilewati.
     */
    private function seedHomeBlocks(): void
    {
        $this->ensureAllowedContent(json_encode(DemoData::INSTITUTION['home_blocks'], JSON_UNESCAPED_UNICODE));

        $blocks = array_map(function (array $block): array {
            $data = $block['data'];

            foreach (['primary', 'secondary', 'button'] as $field) {
                if (isset($data[$field])) {
                    [$entry, $label] = $data[$field];
                    $data[$field] = ($item = $this->linkItem($entry)) === null ? null : [...$item, 'label' => $label];
                }
            }

            if (isset($data['album'])) {
                $data['album_id'] = Album::query()->where('slug', $data['album'])->value('id');
                unset($data['album']);
            }

            return [...$block, 'visible' => true, 'data' => $data];
        }, DemoData::INSTITUTION['home_blocks']);

        app(UpdatePortalHome::class)->handle(['home_blocks' => $blocks], $this->context->user('super-admin'));
    }

    /**
     * Footer demo (F046) lewat `UpdatePortalFooter`, hanya bila footer belum pernah disimpan.
     */
    private function seedFooterWidgets(): void
    {
        $this->ensureAllowedContent(json_encode(DemoData::INSTITUTION['footer_widgets'], JSON_UNESCAPED_UNICODE));

        $widgets = array_map(function (array $widget): array {
            if (isset($widget['data']['items'])) {
                $widget['data']['items'] = $this->linkItems($widget['data']['items']);
            }

            return [...$widget, 'visible' => true];
        }, DemoData::INSTITUTION['footer_widgets']);

        app(UpdatePortalFooter::class)->handle(['footer_widgets' => $widgets], $this->context->user('super-admin'));
    }

    /**
     * @param  list<string>  $entries
     * @return list<array<string, mixed>>
     */
    private function linkItems(array $entries): array
    {
        return array_values(array_filter(array_map(fn (string $entry): ?array => $this->linkItem($entry), $entries)));
    }

    /**
     * Entri tujuan demo → Item navigasi: nama tujuan, atau `page:<slug>` (null bila halaman tidak ada).
     *
     * @return ?array<string, mixed>
     */
    private function linkItem(string $entry): ?array
    {
        if (! str_starts_with($entry, 'page:')) {
            return ['type' => $entry, 'label' => null];
        }

        $id = StaticPage::query()->where('slug', substr($entry, 5))->value('id');

        return $id === null ? null : ['type' => 'page', 'label' => null, 'page_id' => (int) $id];
    }

    /**
     * @return array<string, Category>
     */
    private function seedCategories(): array
    {
        $categories = [];

        foreach (DemoData::POST_CATEGORIES as $name) {
            $categories[$name] = Category::query()->where('type', Category::TYPE_POST)->where('name', $name)->first()
                ?? app(SaveCategory::class)->handle(['type' => Category::TYPE_POST, 'name' => $name]);
        }

        return $categories;
    }

    /**
     * @param  array<string, Category>  $categories
     */
    private function seedPosts(array $categories): void
    {
        $start = $this->context->calendar->admissionYear();
        $replace = ['{S}' => $start.'/'.($start + 1)];
        $actor = $this->context->user('admin-konten');

        foreach (DemoData::POSTS as $index => $row) {
            if (Post::withTrashed()->where('slug', $row['slug'])->exists()) {
                continue;
            }

            $title = strtr($row['title'], $replace);
            $body = strtr($row['body'], $replace);
            $published = $row['offset'] !== null;
            $this->ensureAllowedContent($title.' '.$row['excerpt'].' '.$body);

            app(SavePost::class)->handle([
                'title' => $title,
                'slug' => $row['slug'],
                'category_id' => $categories[$row['category']]->id,
                'unit_id' => null,
                'excerpt' => $row['excerpt'],
                'body' => $body,
                'status' => $published ? Post::STATUS_PUBLISHED : Post::STATUS_DRAFT,
                'published_at' => $published ? $this->context->calendar->daysAgo($row['offset'])->setTime(8, 0)->format('Y-m-d H:i:s') : null,
                'cover' => $row['cover'] ? $this->context->files->png('sampul-'.$row['slug'], $index) : null,
            ], $actor);
        }
    }

    private function seedAlbums(): void
    {
        foreach (DemoData::ALBUMS as $index => $row) {
            $album = Album::query()->where('slug', $row['slug'])->first()
                ?? app(SaveAlbum::class)->handle([
                    'title' => $row['title'],
                    'slug' => $row['slug'],
                    'category_id' => Category::query()->where('type', Category::TYPE_ALBUM)->where('name', $row['category'])->value('id'),
                    'description' => $row['description'],
                ]);

            if ($album->photos()->exists()) {
                continue;
            }

            foreach ($row['captions'] as $position => $caption) {
                app(AddAlbumPhotos::class)->handle($album, [$this->context->files->png($row['slug'].'-'.$position, $index + $position)], $caption);
            }
        }
    }

    /**
     * Penjaga manhaj konten demo: gagal bila teks memuat istilah terlarang.
     */
    private function ensureAllowedContent(string $text): void
    {
        foreach (DemoData::FORBIDDEN_CONTENT_TERMS as $term) {
            if (mb_stripos($text, $term) !== false) {
                throw new RuntimeException("Konten demo memuat istilah terlarang: {$term}");
            }
        }
    }
}
