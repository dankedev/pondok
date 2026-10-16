<?php

namespace App\Actions\Konten;

use App\Models\Album;
use App\Models\Photo;
use App\Models\Post;
use App\Models\Video;

/**
 * Jumlah konten untuk widget dashboard: post terbit, terjadwal, draft (tanpa yang
 * terhapus), album, foto, dan video. Jumlah query tetap.
 */
class SummarizeContent
{
    /**
     * @return array{posts_published: int, posts_scheduled: int, posts_draft: int, albums: int, photos: int, videos: int}
     */
    public function handle(): array
    {
        $now = now();

        $posts = Post::query()
            ->toBase()
            ->selectRaw('SUM(CASE WHEN status = ? AND published_at IS NOT NULL AND published_at <= ? THEN 1 ELSE 0 END) AS published', [Post::STATUS_PUBLISHED, $now])
            ->selectRaw('SUM(CASE WHEN status = ? AND published_at > ? THEN 1 ELSE 0 END) AS scheduled', [Post::STATUS_PUBLISHED, $now])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS draft', [Post::STATUS_DRAFT])
            ->first();

        return [
            'posts_published' => (int) ($posts->published ?? 0),
            'posts_scheduled' => (int) ($posts->scheduled ?? 0),
            'posts_draft' => (int) ($posts->draft ?? 0),
            'albums' => Album::query()->count(),
            'photos' => Photo::query()->count(),
            'videos' => Video::query()->count(),
        ];
    }
}
