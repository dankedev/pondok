<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\SavesProfileSettings;
use App\Models\Album;
use App\Models\User;
use App\Models\Video;

/**
 * Simpan isi beranda portal (halaman Beranda): sambutan, keunggulan, dan media.
 *
 * Teks dan daftar memakai full replace (kosong → null). Tiga key media hanya
 * diubah bila key-nya ada di input: saat modul konten mati, halaman admin tidak
 * mengirimnya sehingga pilihan lama tetap. ID yang tidak ada ditolak.
 */
class UpdatePortalHome
{
    use SavesProfileSettings;

    public const MAX_HIGHLIGHTS = 8;

    /** Field input → [key settings, model, reason]. */
    private const MEDIA = [
        'hero_album_id' => ['portal.hero_album_id', Album::class, 'album_not_found'],
        'profile_video_id' => ['portal.profile_video_id', Video::class, 'video_not_found'],
        'psb_video_id' => ['portal.psb_video_id', Video::class, 'video_not_found'],
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor): void
    {
        $values = [
            'portal.hero_greeting' => $this->optionalText($data['hero_greeting'] ?? null, 'hero_greeting', 100),
            'portal.hero_title' => $this->optionalText($data['hero_title'] ?? null, 'hero_title', 150),
            'portal.hero_subtitle' => $this->optionalText($data['hero_subtitle'] ?? null, 'hero_subtitle', 300),
            'portal.highlights_heading' => $this->optionalText($data['highlights_heading'] ?? null, 'highlights_heading', 100),
            'portal.highlights' => $this->highlights($data['highlights'] ?? null) ?: null,
        ];

        foreach (self::MEDIA as $field => [$key, $model, $reason]) {
            if (array_key_exists($field, $data)) {
                $values[$key] = $this->mediaId($data[$field], $field, $model, $reason);
            }
        }

        $this->saveProfileSettings($values, 'portal_home_updated', $actor);
    }

    /**
     * @return list<array{title: string, description: ?string}>
     */
    private function highlights(mixed $value): array
    {
        $highlights = [];

        foreach ($this->listInput($value, 'highlights', self::MAX_HIGHLIGHTS) as $index => $item) {
            $highlights[] = [
                'title' => $this->requiredText($item['title'] ?? null, "highlights.{$index}.title", 80),
                'description' => $this->optionalText($item['description'] ?? null, "highlights.{$index}.description", 300),
            ];
        }

        return $highlights;
    }

    /**
     * @param  class-string<Album|Video>  $model
     */
    private function mediaId(mixed $value, string $field, string $model, string $reason): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = filter_var($value, FILTER_VALIDATE_INT);

        if ($id === false || $id < 1 || ! $model::query()->whereKey($id)->exists()) {
            $this->fail($field, $reason);
        }

        return $id;
    }
}
