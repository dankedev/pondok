<?php

namespace App\Actions\Konten;

use App\Models\AdmissionWave;
use App\Models\Album;
use App\Models\Post;
use App\Models\Setting;
use App\Models\StaticPage;
use App\Models\Video;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * Data beranda portal. Bila modul konten mati, tabel konten tidak di-query sama sekali.
 */
class GetPortalHomeData
{
    public function __construct(
        private readonly GetSchoolProfile $schoolProfile,
        private readonly SummarizeHtml $summarize,
    ) {}

    /**
     * @return array{school: array<string, ?string>, psbOpen: bool, profileSummary: ?string, latestNews: Collection<int, Post>, latestAchievements: Collection<int, Post>, featuredVideos: Collection<int, Video>, latestAlbums: Collection<int, Album>, contentEnabled: bool}
     */
    public function handle(?CarbonInterface $today = null): array
    {
        $contentEnabled = Setting::moduleEnabled('konten');

        $data = [
            'school' => $this->schoolProfile->handle(),
            'psbOpen' => Setting::moduleEnabled('psb')
                && Route::has('psb.register')
                && AdmissionWave::openForRegistration($today ?? today())->isNotEmpty(),
            'profileSummary' => null,
            'latestNews' => collect(),
            'latestAchievements' => collect(),
            'featuredVideos' => collect(),
            'latestAlbums' => collect(),
            'contentEnabled' => $contentEnabled,
        ];

        if (! $contentEnabled) {
            return $data;
        }

        $summary = $this->summarize->handle(StaticPage::findBySlug('profil')?->body, 300);

        return [
            ...$data,
            'profileSummary' => $summary === '' ? null : $summary,
            'latestNews' => $this->latestPosts([Post::TYPE_NEWS, Post::TYPE_ACTIVITY], 6),
            'latestAchievements' => $this->latestPosts([Post::TYPE_ACHIEVEMENT], 3),
            'featuredVideos' => Video::query()->ordered()->where('is_featured', true)->limit(3)->get(),
            'latestAlbums' => Album::query()
                ->has('photos')
                ->withCount('photos')
                ->with('coverPhoto')
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->limit(4)
                ->get(),
        ];
    }

    /**
     * @param  list<string>  $types
     * @return Collection<int, Post>
     */
    private function latestPosts(array $types, int $limit): Collection
    {
        return Post::query()
            ->published()
            ->whereIn('type', $types)
            ->with(['category', 'unit'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
