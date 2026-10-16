<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\ValidatesContentInput;
use App\Models\Video;
use InvalidArgumentException;

/**
 * Membuat atau mengubah video YouTube. Masukan `youtube` boleh tautan atau ID.
 */
class SaveVideo
{
    use ValidatesContentInput;

    public const TITLE_MAX_LENGTH = 255;

    public const DESCRIPTION_MAX_LENGTH = 2000;

    public const SORT_ORDER_MAX = 32767;

    public function __construct(private readonly ParseYoutubeId $youtube) {}

    /**
     * @param  array{title?: mixed, youtube?: mixed, description?: mixed, is_featured?: mixed, sort_order?: mixed}  $data
     */
    public function handle(array $data, ?Video $video = null): Video
    {
        $title = $this->requiredText($data['title'] ?? null, 'title', self::TITLE_MAX_LENGTH);

        try {
            $youtubeId = $this->youtube->handle(is_scalar($data['youtube'] ?? null) ? (string) $data['youtube'] : null);
        } catch (InvalidArgumentException) {
            $this->fail('youtube', 'youtube_invalid');
        }

        if ($youtubeId === null) {
            $this->fail('youtube', 'youtube_required');
        }

        $description = $this->plainText($data['description'] ?? null, 'description', self::DESCRIPTION_MAX_LENGTH);
        $sortOrder = $this->sortOrder($data['sort_order'] ?? null, self::SORT_ORDER_MAX);

        $video ??= new Video;
        $video->fill([
            'title' => $title,
            'youtube_id' => $youtubeId,
            'description' => $description,
            'is_featured' => (bool) ($data['is_featured'] ?? false),
            'sort_order' => $sortOrder,
        ])->save();

        return $video;
    }
}
