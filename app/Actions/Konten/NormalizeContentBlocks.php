<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\ValidatesContentInput;
use App\Actions\Konten\Concerns\ValidatesNavigationItems;
use App\Models\Album;
use App\Models\Video;
use Illuminate\Http\UploadedFile;

/**
 * Validasi penuh daftar blok konten satu area (F045: `home`) sebelum disimpan. Galat pertama
 * membatalkan semuanya, di field `{area}_blocks.{i}.data.{field}` (bersarang `items.{j}.title`,
 * `primary.url`). Hasilnya `{type, visible, data}` dengan urutan key tetap per tipe, jadi blok
 * tersimpan yang disimpan ulang tidak berubah.
 *
 * Gambar: `UploadedFile` divalidasi (JPG/PNG, 2 MB) dan dikembalikan apa adanya untuk disimpan
 * pemanggil; path string hanya diterima bila ada di `$knownImages`.
 */
class NormalizeContentBlocks
{
    use ValidatesContentInput, ValidatesNavigationItems;

    public const MAX_BLOCKS = 20;

    public const MAX_FEATURES = 8;

    public const LIMITED_TEXT_MAX = 2000;

    public const CTA_VARIANTS = ['light', 'navy', 'gold'];

    public const FEED_SOURCES = ['posts', 'achievements', 'albums', 'videos'];

    /** @var list<string> */
    private array $knownImages = [];

    public function __construct(
        private readonly SanitizeBlockText $sanitizer,
        private readonly StorePublicImage $images,
    ) {}

    /**
     * @param  list<string>  $knownImages
     * @return list<array{type: string, visible: bool, data: array<string, mixed>}>
     */
    public function handle(string $area, mixed $blocks, array $knownImages = []): array
    {
        $this->resetNavigationReferences();
        $this->knownImages = $knownImages;
        $field = "{$area}_blocks";

        if (! is_array($blocks)) {
            $this->fail($field, 'block_type_invalid');
        }

        if (count($blocks) > self::MAX_BLOCKS) {
            $this->fail($field, 'blocks_too_many', ['max' => self::MAX_BLOCKS]);
        }

        $normalized = [];
        $types = ParseContentBlocks::TYPES[$area] ?? [];

        foreach (array_values($blocks) as $index => $block) {
            $prefix = "{$field}.{$index}";
            $type = is_array($block) ? ($block['type'] ?? null) : null;

            if (! is_string($type) || ! in_array($type, $types, true)) {
                $this->fail("{$prefix}.type", 'block_type_invalid');
            }

            if ($type === 'hero' && in_array('hero', array_column($normalized, 'type'), true)) {
                $this->fail("{$prefix}.type", 'block_hero_duplicate');
            }

            $data = is_array($block['data'] ?? null) ? $block['data'] : [];

            $normalized[] = [
                'type' => $type,
                'visible' => (bool) ($block['visible'] ?? true),
                'data' => $this->data($type, $data, "{$prefix}.data"),
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<mixed>  $data
     * @return array<string, mixed>
     */
    private function data(string $type, array $data, string $field): array
    {
        return match ($type) {
            'hero' => $this->hero($data, $field),
            'text_image' => [
                'heading' => $this->optionalText($data['heading'] ?? null, "{$field}.heading", 100),
                'text' => $this->limitedText($data['text'] ?? null, "{$field}.text"),
                ...$this->image($data, $field),
                'button' => $this->button($data['button'] ?? null, "{$field}.button"),
            ],
            'features' => [
                'heading' => $this->optionalText($data['heading'] ?? null, "{$field}.heading", 100),
                'items' => $this->features($data['items'] ?? null, "{$field}.items"),
            ],
            'quote' => [
                'text' => $this->requiredText($data['text'] ?? null, "{$field}.text", 1000, 'text_required'),
                'translation' => $this->optionalText($data['translation'] ?? null, "{$field}.translation", 1000),
                'source' => $this->optionalText($data['source'] ?? null, "{$field}.source", 100),
                'rtl' => (bool) ($data['rtl'] ?? false),
            ],
            'cta' => [
                'eyebrow' => $this->optionalText($data['eyebrow'] ?? null, "{$field}.eyebrow", 60),
                'heading' => $this->requiredText($data['heading'] ?? null, "{$field}.heading", 100),
                'text' => $this->optionalText($data['text'] ?? null, "{$field}.text", 300),
                'primary' => $this->button($data['primary'] ?? null, "{$field}.primary"),
                'secondary' => $this->button($data['secondary'] ?? null, "{$field}.secondary"),
                'variant' => $this->option($data['variant'] ?? null, self::CTA_VARIANTS, "{$field}.variant"),
            ],
            'video' => [
                'heading' => $this->optionalText($data['heading'] ?? null, "{$field}.heading", 100),
                'video_id' => $this->modelId($data['video_id'] ?? null, Video::class, "{$field}.video_id", 'video_not_found')
                    ?? $this->fail("{$field}.video_id", 'video_required'),
            ],
            'feed' => [
                'source' => $this->option($data['source'] ?? null, self::FEED_SOURCES, "{$field}.source"),
                'heading' => $this->optionalText($data['heading'] ?? null, "{$field}.heading", 100),
            ],
        };
    }

    /**
     * @param  array<mixed>  $data
     * @return array<string, mixed>
     */
    private function hero(array $data, string $field): array
    {
        $image = $this->image($data, $field);
        $albumId = $this->modelId($data['album_id'] ?? null, Album::class, "{$field}.album_id", 'album_not_found');

        if ($image['image'] !== null && $albumId !== null) {
            $this->fail("{$field}.image", 'image_with_album');
        }

        return [
            'eyebrow' => $this->optionalText($data['eyebrow'] ?? null, "{$field}.eyebrow", 100),
            'title' => $this->optionalText($data['title'] ?? null, "{$field}.title", 150),
            'text' => $this->optionalText($data['text'] ?? null, "{$field}.text", 300),
            ...$image,
            'album_id' => $albumId,
            'primary' => $this->button($data['primary'] ?? null, "{$field}.primary"),
            'secondary' => $this->button($data['secondary'] ?? null, "{$field}.secondary"),
        ];
    }

    /**
     * Gambar opsional dan teks alternatifnya (wajib bila ada gambar).
     *
     * @param  array<mixed>  $data
     * @return array{image: UploadedFile|string|null, image_alt: ?string}
     */
    private function image(array $data, string $field): array
    {
        $image = $data['image'] ?? null;

        if ($image === null || $image === '') {
            return ['image' => null, 'image_alt' => null];
        }

        if ($image instanceof UploadedFile) {
            $this->images->validate($image, "{$field}.image");
        } elseif (! is_string($image) || ! in_array($image, $this->knownImages, true)) {
            $this->fail("{$field}.image", 'image_invalid', ['max' => StorePublicImage::MAX_KILOBYTES]);
        }

        return [
            'image' => $image,
            'image_alt' => $this->requiredText($data['image_alt'] ?? null, "{$field}.image_alt", 150, 'image_alt_required'),
        ];
    }

    private function limitedText(mixed $value, string $field): ?string
    {
        $html = $this->sanitizer->handle(is_string($value) ? $value : null);

        return $html === '' ? null : $this->optionalText($html, $field, self::LIMITED_TEXT_MAX);
    }

    /**
     * @return list<array{title: string, text: ?string}>
     */
    private function features(mixed $value, string $field): array
    {
        $rows = is_array($value) ? array_values($value) : [];

        if ($rows === []) {
            $this->fail($field, 'items_required');
        }

        if (count($rows) > self::MAX_FEATURES) {
            $this->fail($field, 'list_too_long', ['max' => self::MAX_FEATURES]);
        }

        $items = [];

        foreach ($rows as $index => $row) {
            $row = is_array($row) ? $row : [];
            $items[] = [
                'title' => $this->requiredText($row['title'] ?? null, "{$field}.{$index}.title", 80),
                'text' => $this->optionalText($row['text'] ?? null, "{$field}.{$index}.text", 300),
            ];
        }

        return $items;
    }

    /**
     * Item tombol: tujuan navigasi F043 tanpa grup; kosong = tanpa tombol.
     *
     * @return ?array<string, mixed>
     */
    private function button(mixed $value, string $field): ?array
    {
        return $value === null || $value === [] ? null : $this->item($value, $field, GetPortalNavigation::LABEL_MAX_MENU, 'not_allowed');
    }

    /**
     * @param  list<string>  $options
     */
    private function option(mixed $value, array $options, string $field): string
    {
        if (! is_string($value) || ! in_array($value, $options, true)) {
            $this->fail($field, 'invalid_type');
        }

        return $value;
    }

    /**
     * @param  class-string<Album|Video>  $model
     */
    private function modelId(mixed $value, string $model, string $field, string $reason): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;

        if ($id === false || $id < 1 || ! $model::query()->whereKey($id)->exists()) {
            $this->fail($field, $reason);
        }

        return $id;
    }
}
