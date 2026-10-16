<?php

namespace Database\Seeders\Demo;

use App\Actions\Konten\AddAlbumPhotos;
use App\Actions\Konten\CreateStaticPage;
use App\Actions\Konten\GetSchoolProfile;
use App\Actions\Konten\SaveAlbum;
use App\Actions\Konten\SaveCategory;
use App\Actions\Konten\SavePost;
use App\Actions\Konten\UpdatePortalFooter;
use App\Actions\Konten\UpdatePortalHome;
use App\Actions\Konten\UpdatePortalMenu;
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
            app(UpdateSchoolProfile::class)->handle(DemoData::SCHOOL, $this->context->user('super-admin'));
        }

        // Halaman sistem diisi bila masih kosong; halaman demo lain dibuat bila belum ada (F037).
        foreach (DemoData::pages() as $slug => $page) {
            $model = StaticPage::query()->where('slug', $slug)->first();

            if ($model === null && ! in_array($slug, StaticPage::SYSTEM_SLUGS, true)) {
                $this->ensureAllowedContent($page['title'].' '.$page['body']);
                app(CreateStaticPage::class)->handle([...$page, 'slug' => $slug]);
            } elseif ($model !== null && blank($model->body)) {
                $this->ensureAllowedContent($page['title'].' '.$page['body']);
                app(UpdateStaticPage::class)->handle($model, $page);
            }
        }
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

        $identity = collect($profile)->only(['name', 'short_name', 'arabic_name', 'tagline', 'address', 'phone', 'email'])->all();
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
                'map_url' => $profile['map_url'],
                'contacts' => $demo['contacts'],
                'social_links' => $demo['social_links'],
            ], $actor);
        }

        if ($profile['hero']['greeting'] === null && $profile['highlights'] === []) {
            $this->ensureAllowedContent(json_encode($demo['home'], JSON_UNESCAPED_UNICODE));

            app(UpdatePortalHome::class)->handle([
                ...$demo['home'],
                'hero_album_id' => Album::query()->where('slug', $demo['hero_album_slug'])->value('id'),
            ], $actor);
        }

        if ($profile['footer']['quote_arabic'] === null && $profile['footer']['links'] === []) {
            $this->ensureAllowedContent(json_encode($demo['footer'], JSON_UNESCAPED_UNICODE));

            // Tautan Visi & misi kini diatur admin (F037): contoh tautan halaman di footer.
            $vision = StaticPage::findBySlug('visi-misi');
            $footer = $demo['footer'];

            if ($vision !== null) {
                $footer['footer_links'][] = ['label' => null, 'page_id' => $vision->id];
            }

            app(UpdatePortalFooter::class)->handle($footer, $actor);
        }

        if (Setting::get('portal.menu_items') === null) {
            $this->seedMenu();
        }
    }

    /**
     * Menu header demo dari brief (F037) lewat Action panel.
     */
    private function seedMenu(): void
    {
        $pageIds = StaticPage::query()->pluck('id', 'slug');
        $items = [];

        foreach (DemoData::MENU as [$entry, $visible]) {
            if (! str_starts_with($entry, 'page:')) {
                $items[] = ['type' => 'builtin', 'key' => $entry, 'visible' => $visible];
            } elseif ($pageIds->has(substr($entry, 5))) {
                $items[] = ['type' => 'page', 'page_id' => $pageIds->get(substr($entry, 5)), 'label' => null, 'visible' => $visible];
            }
        }

        app(UpdatePortalMenu::class)->handle(['items' => $items, 'show_register' => true], $this->context->user('super-admin'));
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
                'type' => $row['type'],
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
