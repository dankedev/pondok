<?php

namespace App\Actions\Konten;

use App\Models\AdmissionWave;
use App\Models\Album;
use App\Models\Photo;
use App\Models\Post;
use App\Models\Setting;
use App\Models\Video;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/**
 * Data beranda portal berbasis blok (F045): blok `portal.home_blocks` dibaca lewat
 * `ParseContentBlocks`, lalu rujukannya dimuat per jenis sekali (album hero, video, satu
 * batch tombol lewat `GetPortalNavigation::resolveItems`, satu query per blok feed).
 *
 * Blok yang tidak punya isi untuk dirender (field wajib kosong, video/album hilang, feed tanpa
 * item, blok konten saat modul konten mati) dibuang, sehingga view cukup merender `blocks`.
 * Bila modul konten mati, tabel konten tidak di-query sama sekali.
 */
class GetPortalHomeData
{
    public const HERO_PHOTOS = 10;

    /** Jumlah item tetap per sumber feed. */
    public const FEED_LIMITS = ['posts' => 6, 'achievements' => 3, 'videos' => 3, 'albums' => 4];

    /** Field Item tombol per tipe blok. */
    private const BUTTON_FIELDS = ['hero' => ['primary', 'secondary'], 'text_image' => ['button'], 'cta' => ['primary', 'secondary']];

    public function __construct(
        private readonly GetSchoolProfile $schoolProfile,
        private readonly ParseContentBlocks $parse,
        private readonly GetPortalNavigation $navigation,
    ) {}

    /**
     * Tiap blok: `{type, data, buttons?, album?, psbOpen?, video?, items?, url?}`.
     *
     * @return array{school: array<string, mixed>, blocks: list<array<string, mixed>>, hasHero: bool}
     */
    public function handle(?CarbonInterface $today = null): array
    {
        $school = $this->schoolProfile->handle();
        $contentEnabled = Setting::moduleEnabled('konten');

        $blocks = array_values(array_filter(
            $this->parse->handle('home', $school['home_blocks']),
            fn (array $block): bool => $this->hasContent($block, $contentEnabled),
        ));

        $hasHero = in_array('hero', array_column($blocks, 'type'), true);
        $psbOpen = $this->psbOpen($blocks, $hasHero, $today);
        $buttons = $this->buttons($blocks, $psbOpen);
        $videos = $this->videos($blocks);

        $rendered = [];

        foreach ($blocks as $index => $block) {
            $block = [...$block, 'buttons' => $buttons[$index] ?? []];

            $block = match ($block['type']) {
                'hero' => [...$block, 'psbOpen' => $psbOpen, 'album' => $contentEnabled ? $this->heroAlbum($block['data']['album_id']) : null, 'image' => $this->imageUrl($block['data']['image'])],
                'text_image' => [...$block, 'image' => $this->imageUrl($block['data']['image'])],
                'video' => ($video = $videos->get($block['data']['video_id'])) === null ? null : [...$block, 'video' => $video],
                'feed' => $this->feed($block),
                default => $block,
            };

            if ($block !== null) {
                $rendered[] = $block;
            }
        }

        return ['school' => $school, 'blocks' => $rendered, 'hasHero' => $hasHero];
    }

    /**
     * Cek isi tanpa query; rujukan (video, item feed) dicek setelah dimuat.
     *
     * @param  array{type: string, data: array<string, mixed>}  $block
     */
    private function hasContent(array $block, bool $contentEnabled): bool
    {
        $data = $block['data'];

        return match ($block['type']) {
            'text_image' => $data['heading'] !== null || $data['text'] !== null || $data['image'] !== null,
            'features' => $data['items'] !== [],
            'quote' => $data['text'] !== null,
            'cta' => $data['heading'] !== null,
            'video' => $contentEnabled && $data['video_id'] !== null,
            'feed' => $contentEnabled && isset(self::FEED_LIMITS[$data['source']]),
            default => true,
        };
    }

    /**
     * Gelombang terbuka hanya dicek bila dipakai: badge hero atau tombol `psb.register`.
     *
     * @param  list<array<string, mixed>>  $blocks
     */
    private function psbOpen(array $blocks, bool $hasHero, ?CarbonInterface $today): bool
    {
        $usesRegister = collect($blocks)->contains(fn (array $block): bool => collect(self::BUTTON_FIELDS[$block['type']] ?? [])
            ->contains(fn (string $field): bool => ($block['data'][$field]['type'] ?? null) === 'psb.register'));

        return ($hasHero || $usesRegister)
            && Setting::moduleEnabled('psb')
            && Route::has('psb.register')
            && AdmissionWave::openForRegistration($today ?? today())->isNotEmpty();
    }

    /**
     * Tombol semua blok diselesaikan dalam satu panggilan `resolveItems` (satu batch query).
     * `psb.register` hanya saat gelombang dibuka.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return array<int, list<array<string, mixed>>>
     */
    private function buttons(array $blocks, bool $psbOpen): array
    {
        $lists = [];

        foreach ($blocks as $index => $block) {
            $items = [];

            foreach (self::BUTTON_FIELDS[$block['type']] ?? [] as $field) {
                $item = $block['data'][$field];

                if ($item !== null && ($psbOpen || $item['type'] !== 'psb.register')) {
                    $items[] = $item;
                }
            }

            if ($items !== []) {
                $lists[$index] = $items;
            }
        }

        return $lists === [] ? [] : $this->navigation->resolveItems($lists, GetPortalNavigation::LABEL_MAX_MENU);
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return Collection<int, Video>
     */
    private function videos(array $blocks): Collection
    {
        $ids = array_column(array_column(array_filter($blocks, fn (array $block): bool => $block['type'] === 'video'), 'data'), 'video_id');

        return $ids === [] ? collect() : Video::query()->whereIn('id', array_unique($ids))->get()->keyBy('id');
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

    private function imageUrl(?string $path): ?string
    {
        return $path === null ? null : Storage::disk('public')->url($path);
    }

    /**
     * @param  array<string, mixed>  $block
     * @return ?array<string, mixed>
     */
    private function feed(array $block): ?array
    {
        $source = $block['data']['source'];
        $limit = self::FEED_LIMITS[$source];

        $items = match ($source) {
            'posts' => $this->latestPosts([Post::TYPE_NEWS, Post::TYPE_ACTIVITY], $limit),
            'achievements' => $this->latestPosts([Post::TYPE_ACHIEVEMENT], $limit),
            'videos' => Video::query()->ordered()->where('is_featured', true)->limit($limit)->get(),
            'albums' => Album::query()->has('photos')->withCount('photos')->with('coverPhoto')
                ->orderByDesc('updated_at')->orderByDesc('id')->limit($limit)->get(),
        };

        if ($items->isEmpty()) {
            return null;
        }

        $url = match ($source) {
            'posts' => route('portal.posts.index'),
            'achievements' => route('portal.posts.index', ['jenis' => Post::TYPE_ACHIEVEMENT]),
            'videos' => route('portal.videos.index'),
            'albums' => route('portal.albums.index'),
        };

        return [...$block, 'items' => $items, 'url' => $url];
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
