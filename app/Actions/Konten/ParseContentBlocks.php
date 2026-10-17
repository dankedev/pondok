<?php

namespace App\Actions\Konten;

/**
 * Baca blok konten tersimpan (mis. `portal.home_blocks`) dengan toleran: blok rusak, tipe tak
 * dikenal, atau tipe bukan milik area dibuang per blok, dan blok tersembunyi dibuang kecuali
 * `$withHidden` (form admin). Portal tidak pernah merender JSON blok tanpa lewat Action ini.
 *
 * Sanitasi saat baca: teks terbatas lewat `SanitizeBlockText`, Item tombol lewat
 * `GetPortalNavigation::parseItem` (URL tidak aman → tombol dibuang), teks polos dipaksa
 * string atau `null`, id dipaksa bilangan bulat positif atau `null`.
 */
class ParseContentBlocks
{
    /** Tipe blok per area. */
    public const TYPES = [
        'home' => ['hero', 'text_image', 'features', 'quote', 'cta', 'video', 'feed'],
    ];

    /** Jenis field per tipe: text (polos), html (teks terbatas), item (tombol), id, bool, features. */
    private const FIELDS = [
        'hero' => ['eyebrow' => 'text', 'title' => 'text', 'text' => 'text', 'image' => 'text', 'image_alt' => 'text', 'album_id' => 'id', 'primary' => 'item', 'secondary' => 'item'],
        'text_image' => ['heading' => 'text', 'text' => 'html', 'image' => 'text', 'image_alt' => 'text', 'button' => 'item'],
        'features' => ['heading' => 'text', 'items' => 'features'],
        'quote' => ['text' => 'text', 'translation' => 'text', 'source' => 'text', 'rtl' => 'bool'],
        'cta' => ['eyebrow' => 'text', 'heading' => 'text', 'text' => 'text', 'primary' => 'item', 'secondary' => 'item', 'variant' => 'text'],
        'video' => ['heading' => 'text', 'video_id' => 'id'],
        'feed' => ['source' => 'text', 'heading' => 'text'],
    ];

    public function __construct(private readonly SanitizeBlockText $sanitizer) {}

    /**
     * @return list<array{type: string, visible: bool, data: array<string, mixed>}>
     */
    public function handle(string $area, mixed $stored, bool $withHidden = false): array
    {
        $blocks = [];

        foreach (is_array($stored) ? $stored : [] as $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : null;

            if (! is_string($type) || ! in_array($type, self::TYPES[$area] ?? [], true)) {
                continue;
            }

            $visible = ($block['visible'] ?? true) !== false;

            if (! $visible && ! $withHidden) {
                continue;
            }

            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $parsed = [];

            foreach (self::FIELDS[$type] as $field => $kind) {
                $parsed[$field] = $this->value($kind, $data[$field] ?? null);
            }

            $blocks[] = ['type' => $type, 'visible' => $visible, 'data' => $parsed];
        }

        return $blocks;
    }

    private function value(string $kind, mixed $value): mixed
    {
        return match ($kind) {
            'text' => $this->text($value),
            'html' => is_string($value) && ($html = $this->sanitizer->handle($value)) !== '' ? $html : null,
            'item' => is_array($value) ? GetPortalNavigation::parseItem($value, false) : null,
            'id' => is_int($value) || is_string($value) ? (($id = filter_var($value, FILTER_VALIDATE_INT)) === false || $id < 1 ? null : $id) : null,
            'bool' => $value === true,
            'features' => $this->features($value),
        };
    }

    private function text(mixed $value): ?string
    {
        return is_scalar($value) && ! is_bool($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * @return list<array{title: string, text: ?string}>
     */
    private function features(mixed $value): array
    {
        $items = [];

        foreach (is_array($value) ? $value : [] as $row) {
            $title = is_array($row) ? $this->text($row['title'] ?? null) : null;

            if ($title !== null) {
                $items[] = ['title' => $title, 'text' => $this->text($row['text'] ?? null)];
            }
        }

        return $items;
    }
}
