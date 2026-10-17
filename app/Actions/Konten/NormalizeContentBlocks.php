<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\ValidatesContentInput;
use App\Actions\Konten\Concerns\ValidatesNavigationItems;
use App\Models\Album;
use App\Models\Video;
use Illuminate\Http\UploadedFile;

/**
 * Validasi penuh daftar blok konten satu area (F045 `home`, F046 widget `footer`) sebelum disimpan.
 * Galat pertama membatalkan semuanya, di field `home_blocks.{i}.data.{field}` atau
 * `footer_widgets.{i}.data.{field}` (bersarang `items.{j}.title`, `primary.url`). Hasilnya `{type, visible, data}` dengan urutan key tetap per tipe, jadi blok
 * tersimpan yang disimpan ulang tidak berubah.
 *
 * Gambar: `UploadedFile` divalidasi (JPG/PNG, 2 MB) dan dikembalikan apa adanya untuk disimpan
 * pemanggil; path string hanya diterima bila ada di `$knownImages`.
 */
class NormalizeContentBlocks
{
    use ValidatesContentInput, ValidatesNavigationItems;

    public const MAX_BLOCKS = 20;

    /** Batas widget footer, termasuk yang tersembunyi (F046). */
    public const MAX_WIDGETS = 4;

    /** Field input dan batas jumlah blok per area (`reason` = galat bila lebih). */
    public const AREAS = [
        'home' => ['field' => 'home_blocks', 'max' => self::MAX_BLOCKS, 'reason' => 'blocks_too_many'],
        'footer' => ['field' => 'footer_widgets', 'max' => self::MAX_WIDGETS, 'reason' => 'list_too_long'],
    ];

    public const MAX_FEATURES = 8;

    public const LIMITED_TEXT_MAX = 2000;

    /** Widget footer (F046): batas judul, isi teks (HTML tersanitasi), tautan, dan label tautan. */
    public const WIDGET_HEADING_MAX = 60;

    public const WIDGET_TEXT_MAX = 6000;

    public const WIDGET_LINKS_MAX = 12;

    public const WIDGET_LINK_LABEL_MAX = 60;

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
        ['field' => $field, 'max' => $max, 'reason' => $reason] = self::AREAS[$area];

        if (! is_array($blocks)) {
            $this->fail($field, 'block_type_invalid');
        }

        if (count($blocks) > $max) {
            $this->fail($field, $reason, ['max' => $max]);
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
                'text' => $this->limitedText($data['text'] ?? null, "{$field}.text", self::LIMITED_TEXT_MAX),
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
            'text' => [
                'heading' => $this->widgetHeading($data, $field),
                'body' => $this->limitedText($data['body'] ?? null, "{$field}.body", self::WIDGET_TEXT_MAX),
                'show_logo' => (bool) ($data['show_logo'] ?? false),
            ],
            'links' => [
                'heading' => $this->widgetHeading($data, $field),
                'items' => $this->links($data['items'] ?? null, "{$field}.items"),
            ],
            'social', 'contacts' => ['heading' => $this->widgetHeading($data, $field)],
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

    private function limitedText(mixed $value, string $field, int $max): ?string
    {
        $html = $this->sanitizer->handle(is_string($value) ? $value : null);

        return $html === '' ? null : $this->optionalText($html, $field, $max);
    }

    /**
     * @param  array<mixed>  $data
     */
    private function widgetHeading(array $data, string $field): ?string
    {
        return $this->optionalText($data['heading'] ?? null, "{$field}.heading", self::WIDGET_HEADING_MAX);
    }

    /**
     * Tautan widget footer: 1–12 Item navigasi F043 tanpa grup.
     *
     * @return list<array<string, mixed>>
     */
    private function links(mixed $value, string $field): array
    {
        $rows = is_array($value) ? array_values($value) : [];

        if ($rows === []) {
            $this->fail($field, 'items_required');
        }

        if (count($rows) > self::WIDGET_LINKS_MAX) {
            $this->fail($field, 'list_too_long', ['max' => self::WIDGET_LINKS_MAX]);
        }

        return array_map(fn (mixed $row, int $index): array => $this->item($row, "{$field}.{$index}", self::WIDGET_LINK_LABEL_MAX, 'not_allowed'), $rows, array_keys($rows));
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
