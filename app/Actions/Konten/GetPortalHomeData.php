<?php

namespace App\Actions\Konten;

use App\Models\AdmissionWave;
use App\Models\Album;
use App\Models\Photo;
use App\Models\Post;
use App\Models\Setting;
use App\Models\StaticPage;
use App\Models\Video;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * Data beranda portal. Bila modul konten mati, tabel konten tidak di-query sama sekali.
 *
 * Media beranda (album slider, video profil, video PSB) dirujuk dari settings tanpa
 * FK: rujukan yang hilang menghasilkan null sehingga seksinya tidak dirender.
 */
class GetPortalHomeData
{
    public const HERO_PHOTOS = 10;

    public function __construct(
        private readonly GetSchoolProfile $schoolProfile,
        private readonly SummarizeHtml $summarize,
    ) {}

    /**
     * @return array{school: array<string, mixed>, psbOpen: bool, profileTitle: ?string, profileSummary: ?string, heroAlbum: ?array{title: string, photos: Collection<int, Photo>}, profileVideo: ?Video, psbVideo: ?Video, latestNews: Collection<int, Post>, latestAchievements: Collection<int, Post>, featuredVideos: Collection<int, Video>, latestAlbums: Collection<int, Album>, contentEnabled: bool}
     */
    public function handle(?CarbonInterface $today = null): array
    {
        $contentEnabled = Setting::moduleEnabled('konten');

        $school = $this->schoolProfile->handle();
        $psbEnabled = Setting::moduleEnabled('psb');

        $data = [
            'school' => $school,
            'psbOpen' => $psbEnabled
                && Route::has('psb.register')
                && AdmissionWave::openForRegistration($today ?? today())->isNotEmpty(),
            'profileTitle' => null,
            'profileSummary' => null,
            'heroAlbum' => null,
            'profileVideo' => null,
            'psbVideo' => null,
            'latestNews' => collect(),
            'latestAchievements' => collect(),
            'featuredVideos' => collect(),
            'latestAlbums' => collect(),
            'contentEnabled' => $contentEnabled,
        ];

        if (! $contentEnabled) {
            return $data;
        }

        // Seksi profil beranda hanya dari halaman `profil` yang terbit (F037).
        $profilePage = StaticPage::query()->published()->where('slug', 'profil')->first();
        $summary = $this->summarize->handle($profilePage?->body, 300);

        return [
            ...$data,
            'profileTitle' => $summary === '' ? null : $profilePage?->title,
            'profileSummary' => $summary === '' ? null : $summary,
            'heroAlbum' => $this->heroAlbum($school['hero_album_id']),
            'profileVideo' => $this->video($school['profile_video_id']),
            'psbVideo' => $psbEnabled ? $this->video($school['psb_video_id']) : null,
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
     * Album slider: maksimal 10 foto urut `sort_order` lalu `id`; album kosong atau hilang → null.
     *
     * @return ?array{title: string, photos: Collection<int, Photo>}
     */
    private function heroAlbum(?int $id): ?array
    {
        $album = $id === null ? null : Album::query()->find($id);

        if ($album === null) {
            return null;
        }

        $photos = $album->photos()->reorder()->orderBy('sort_order')->orderBy('id')->limit(self::HERO_PHOTOS)->get();

        return $photos->isEmpty() ? null : ['title' => $album->title, 'photos' => $photos];
    }

    private function video(?int $id): ?Video
    {
        return $id === null ? null : Video::query()->find($id);
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
