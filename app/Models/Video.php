<?php

namespace App\Models;

use App\Actions\Konten\ParseYoutubeId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Video YouTube untuk portal publik (F016).
 */
#[Fillable(['title', 'youtube_id', 'description', 'is_featured', 'sort_order'])]
class Video extends Model
{
    protected function casts(): array
    {
        return [
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Urutan portal: unggulan dulu, lalu nomor urut, lalu yang terbaru.
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderByDesc('is_featured')->orderBy('sort_order')->orderByDesc('id');
    }

    public function embedUrl(): string
    {
        return self::embedUrlFor($this->youtube_id);
    }

    /**
     * URL embed YouTube mode privasi (nocookie); dipakai juga untuk video pada post.
     */
    public static function embedUrlFor(string $youtubeId): string
    {
        return "https://www.youtube-nocookie.com/embed/{$youtubeId}";
    }

    public function watchUrl(): string
    {
        return ParseYoutubeId::watchUrl($this->youtube_id);
    }
}
