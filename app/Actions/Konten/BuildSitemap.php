<?php

namespace App\Actions\Konten;

use App\Models\Album;
use App\Models\Post;
use App\Models\Setting;
use App\Models\StaticPage;
use Illuminate\Support\Facades\Route;

/**
 * Entri `sitemap.xml` portal. URL dibangun dari `route()` (root dipaksa ke `APP_URL`
 * oleh `ForcePortalRootUrl`); tidak ada URL dengan query string.
 */
class BuildSitemap
{
    public const MAX_POSTS = 1000;

    public function __construct(private readonly SanitizeContentHtml $sanitize) {}

    /**
     * @return list<array{loc: string, lastmod: ?string}>
     */
    public function handle(): array
    {
        $entries = [['loc' => route('portal.home'), 'lastmod' => null]];

        if (Setting::moduleEnabled('psb') && Route::has('psb.register')) {
            $entries[] = ['loc' => route('psb.register'), 'lastmod' => null];
        }

        if (! Setting::moduleEnabled('konten')) {
            return $entries;
        }

        foreach (['portal.posts.index', 'portal.albums.index', 'portal.videos.index'] as $name) {
            $entries[] = ['loc' => route($name), 'lastmod' => null];
        }

        StaticPage::query()->whereIn('slug', StaticPage::SLUGS)->orderBy('id')->get()
            ->reject(fn (StaticPage $page): bool => $this->sanitize->isBlank($this->sanitize->handle($page->body)))
            ->each(function (StaticPage $page) use (&$entries): void {
                $entries[] = ['loc' => route('portal.pages.show', $page->slug), 'lastmod' => $page->updated_at?->toAtomString()];
            });

        Post::query()->published()
            ->orderByDesc('published_at')->orderByDesc('id')
            ->limit(self::MAX_POSTS)
            ->get(['id', 'slug', 'updated_at'])
            ->each(function (Post $post) use (&$entries): void {
                $entries[] = ['loc' => route('portal.posts.show', $post->slug), 'lastmod' => $post->updated_at?->toAtomString()];
            });

        Album::query()->has('photos')
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->get(['id', 'slug', 'updated_at'])
            ->each(function (Album $album) use (&$entries): void {
                $entries[] = ['loc' => route('portal.albums.show', $album->slug), 'lastmod' => $album->updated_at?->toAtomString()];
            });

        return $entries;
    }
}
