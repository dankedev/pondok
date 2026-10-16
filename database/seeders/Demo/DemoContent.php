<?php

namespace Database\Seeders\Demo;

use App\Actions\Konten\AddAlbumPhotos;
use App\Actions\Konten\SaveAlbum;
use App\Actions\Konten\SaveCategory;
use App\Actions\Konten\SavePost;
use App\Actions\Konten\SaveVideo;
use App\Actions\Konten\UpdateSchoolProfile;
use App\Actions\Konten\UpdateStaticPage;
use App\Models\Album;
use App\Models\Category;
use App\Models\Post;
use App\Models\Setting;
use App\Models\StaticPage;
use App\Models\Video;
use RuntimeException;

/**
 * Profil sekolah, halaman statis, kategori, berita, album, dan video demo.
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

        foreach (DemoData::pages() as $slug => $page) {
            $model = StaticPage::query()->where('slug', $slug)->first();

            if ($model !== null && blank($model->body)) {
                app(UpdateStaticPage::class)->handle($model, $page);
            }
        }
    }

    public function run(): void
    {
        $this->seedPosts($this->seedCategories());
        $this->seedAlbums();
        $this->seedVideos();
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
                'cover' => $row['cover'] ? $this->context->files->png('sampul-'.$row['slug'], $title, $index) : null,
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
                app(AddAlbumPhotos::class)->handle($album, [$this->context->files->png($row['slug'].'-'.$position, $caption, $index + $position)], $caption);
            }
        }
    }

    private function seedVideos(): void
    {
        foreach (DemoData::VIDEOS as $position => $row) {
            if (Video::query()->where('youtube_id', $row['youtube'])->exists()) {
                continue;
            }

            app(SaveVideo::class)->handle([
                'title' => $row['title'],
                'youtube' => $row['youtube'],
                'description' => $row['description'],
                'is_featured' => $row['featured'],
                'sort_order' => $position + 1,
            ]);
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
