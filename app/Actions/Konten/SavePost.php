<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\ReplacesStoredFiles;
use App\Actions\Konten\Concerns\ValidatesContentInput;
use App\Models\Category;
use App\Models\Post;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Membuat atau mengubah berita/kegiatan/prestasi.
 *
 * Validasi berhenti di galat pertama dan tidak menulis apa pun. Body disanitasi
 * (batas byte, media non-http(s) ditolak). Status `terbit` tanpa tanggal memakai
 * tanggal terbit lama atau sekarang. Penulis diisi saat dibuat dan tidak berubah.
 * Sampul baru menggantikan yang lama; berkas lama dihapus setelah commit.
 * `achievement_id` dan `meta` tidak disentuh.
 */
class SavePost
{
    use ReplacesStoredFiles, ValidatesContentInput;

    public const TITLE_MAX_LENGTH = 255;

    public const EXCERPT_MAX_LENGTH = 255;

    private const DATE_FORMATS = ['Y-m-d H:i:s', 'Y-m-d H:i'];

    public function __construct(
        private readonly SanitizeContentHtml $sanitizer,
        private readonly ParseYoutubeId $youtube,
        private readonly GenerateSlug $slugs,
        private readonly StorePublicImage $images,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor, ?Post $post = null): Post
    {
        $title = $this->requiredText($data['title'] ?? null, 'title', self::TITLE_MAX_LENGTH);
        $slug = $this->slugs->handle(Post::class, $title, $this->stringOrNull($data['slug'] ?? null), Post::SLUG_MAX_LENGTH, $post);
        $categoryId = $this->categoryId($data['category_id'] ?? null);
        $unitId = $this->unitId($data['unit_id'] ?? null);
        $excerpt = $this->plainText($data['excerpt'] ?? null, 'excerpt', self::EXCERPT_MAX_LENGTH);
        $body = $this->body($data['body'] ?? null);
        $youtubeUrl = $this->youtubeUrl($data['youtube_url'] ?? null);

        $status = $data['status'] ?? null;

        if (! is_string($status) || ! in_array($status, Post::STATUSES, true)) {
            $this->fail('status', 'invalid_status');
        }

        $publishedAt = $this->publishedAt($data['published_at'] ?? null);

        if ($status === Post::STATUS_PUBLISHED && $publishedAt === null) {
            $publishedAt = $post?->published_at ?? now();
        }

        $cover = $data['cover'] ?? null;
        $coverExtension = $cover === null ? null : $this->images->validate($cover, 'cover');

        $post ??= new Post;
        $oldCover = $post->cover_path;
        $coverPath = $oldCover;

        if ($cover instanceof UploadedFile && $coverExtension !== null) {
            $coverPath = $this->images->store($cover, Post::COVER_DIRECTORY, $coverExtension);
        } elseif ((bool) ($data['remove_cover'] ?? false)) {
            $coverPath = null;
        }

        $newPaths = $coverPath !== $oldCover && $coverPath !== null ? [$coverPath] : [];
        $oldPaths = $coverPath !== $oldCover ? [$oldCover] : [];

        try {
            return $this->saveWithFiles($newPaths, $oldPaths, function () use ($post, $actor, $title, $slug, $categoryId, $unitId, $excerpt, $body, $youtubeUrl, $status, $publishedAt, $coverPath): Post {
                if (! $post->exists) {
                    $post->author_id = $actor->getKey();
                }

                $post->fill([
                    'title' => $title,
                    'slug' => $slug,
                    'category_id' => $categoryId,
                    'unit_id' => $unitId,
                    'excerpt' => $excerpt,
                    'body' => $body,
                    'youtube_url' => $youtubeUrl,
                    'status' => $status,
                    'published_at' => $publishedAt,
                    'cover_path' => $coverPath,
                ])->save();

                return $post;
            });
        } catch (UniqueConstraintViolationException) {
            $this->fail('slug', 'slug_taken');
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : null;
    }

    private function categoryId(mixed $value): ?int
    {
        if (blank($value)) {
            return null;
        }

        $id = Category::query()->ofType(Category::TYPE_POST)->whereKey($value)->value('id');

        if ($id === null) {
            $this->fail('category_id', 'invalid_category');
        }

        return (int) $id;
    }

    private function unitId(mixed $value): ?int
    {
        if (blank($value)) {
            return null;
        }

        $id = Unit::query()->whereKey($value)->value('id');

        if ($id === null) {
            $this->fail('unit_id', 'invalid_unit');
        }

        return (int) $id;
    }

    private function body(mixed $value): string
    {
        $html = is_scalar($value) ? (string) $value : '';

        if (strlen($html) > SanitizeContentHtml::MAX_INPUT_BYTES) {
            $this->fail('body', 'too_long', ['max' => SanitizeContentHtml::MAX_INPUT_BYTES]);
        }

        $body = $this->sanitizer->handle($html);

        if ($this->sanitizer->hasDisallowedMediaSource($body)) {
            $this->fail('body', 'media_source_invalid');
        }

        if ($this->sanitizer->isBlank($body)) {
            $this->fail('body', 'body_required');
        }

        return $body;
    }

    private function youtubeUrl(mixed $value): ?string
    {
        try {
            $id = $this->youtube->handle(is_scalar($value) ? (string) $value : null);
        } catch (Throwable) {
            $this->fail('youtube_url', 'youtube_invalid');
        }

        return $id === null ? null : ParseYoutubeId::watchUrl($id);
    }

    /**
     * Hanya Carbon atau string `Y-m-d H:i[:s]` (keluaran DateTimePicker); tanggal relatif ditolak.
     */
    private function publishedAt(mixed $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if (is_string($value)) {
            foreach (self::DATE_FORMATS as $format) {
                try {
                    $date = CarbonImmutable::createFromFormat('!'.$format, trim($value), config('app.timezone'));
                } catch (Throwable) {
                    continue;
                }

                $errors = CarbonImmutable::getLastErrors();

                if ($date instanceof CarbonImmutable && $date->format($format) === trim($value)
                    && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                    return $date;
                }
            }
        }

        $this->fail('published_at', 'published_at_invalid');
    }
}
