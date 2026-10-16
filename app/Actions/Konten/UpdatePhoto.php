<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\ValidatesContentInput;
use App\Models\Photo;

/**
 * Ubah keterangan dan nomor urut foto.
 */
class UpdatePhoto
{
    use ValidatesContentInput;

    /**
     * @param  array{caption?: mixed, sort_order?: mixed}  $data
     */
    public function handle(Photo $photo, array $data): Photo
    {
        $caption = $this->optionalText($data['caption'] ?? null, 'caption', AddAlbumPhotos::CAPTION_MAX_LENGTH);
        $sortOrder = $this->sortOrder($data['sort_order'] ?? null, Photo::SORT_ORDER_MAX, (int) $photo->sort_order);

        $photo->fill(['caption' => $caption, 'sort_order' => $sortOrder])->save();

        return $photo;
    }
}
