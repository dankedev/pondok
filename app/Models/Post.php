<?php

namespace App\Models;

use App\Actions\Konten\ParseYoutubeId;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Berita, kegiatan, dan prestasi untuk portal publik (F016). Ditulis lewat
 * `App\Actions\Konten\SavePost`; body adalah HTML tersanitasi. Soft delete.
 */
#[Fillable(['category_id', 'unit_id', 'type', 'title', 'slug', 'excerpt', 'body', 'cover_path', 'youtube_url', 'status', 'published_at'])]
class Post extends Model
{
    use SoftDeletes;

    public const TYPE_ACTIVITY = 'kegiatan';

    public const TYPE_ACHIEVEMENT = 'prestasi';

    public const TYPE_NEWS = 'berita';

    public const TYPES = [self::TYPE_ACTIVITY, self::TYPE_ACHIEVEMENT, self::TYPE_NEWS];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'terbit';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED];

    /** Status tampilan (dihitung, tidak disimpan): terbit dengan `published_at` di masa depan. */
    public const DISPLAY_SCHEDULED = 'terjadwal';

    public const STATUS_COLORS = [
        self::STATUS_DRAFT => 'gray',
        self::STATUS_PUBLISHED => 'success',
        self::DISPLAY_SCHEDULED => 'info',
    ];

    public const COVER_DIRECTORY = 'posts/covers';

    public const SLUG_MAX_LENGTH = 200;

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Prestasi sumber berita (F015). `achievement_id` diisi `PublishAchievementAsPost`.
     */
    public function achievement(): BelongsTo
    {
        return $this->belongsTo(Achievement::class);
    }

    /**
     * Post yang tampil di portal: terbit dan waktu terbitnya sudah lewat.
     */
    public function scopePublished(Builder $query, ?CarbonInterface $now = null): void
    {
        $query->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', $now ?? now());
    }

    public function scopeOfType(Builder $query, string $type): void
    {
        $query->where('type', $type);
    }

    public function displayStatus(): string
    {
        if ($this->status === self::STATUS_PUBLISHED && $this->published_at !== null && $this->published_at->isFuture()) {
            return self::DISPLAY_SCHEDULED;
        }

        return (string) $this->status;
    }

    public function displayStatusColor(): string
    {
        return self::STATUS_COLORS[$this->displayStatus()] ?? 'gray';
    }

    public function coverUrl(): ?string
    {
        return $this->cover_path === null ? null : Storage::disk('public')->url($this->cover_path);
    }

    public function youtubeId(): ?string
    {
        try {
            return app(ParseYoutubeId::class)->handle($this->youtube_url);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
