<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\ReplacesStoredFiles;
use App\Actions\Konten\Concerns\ValidatesContentInput;
use App\Models\Photo;
use Illuminate\Http\UploadedFile;

/**
 * Ubah keterangan, nomor urut, dan (opsional) gambar foto. Gambar lama dihapus
 * dari disk `public` setelah commit; gambar baru dibuang bila penyimpanan gagal.
 */
class UpdatePhoto
{
    use ReplacesStoredFiles;
    use ValidatesContentInput;

    public function __construct(private readonly StorePublicImage $images) {}

    /**
     * @param  array{caption?: mixed, sort_order?: mixed, image?: mixed}  $data
     */
    public function handle(Photo $photo, array $data): Photo
    {
        $caption = $this->optionalText($data['caption'] ?? null, 'caption', AddAlbumPhotos::CAPTION_MAX_LENGTH);
        $sortOrder = $this->sortOrder($data['sort_order'] ?? null, Photo::SORT_ORDER_MAX, (int) $photo->sort_order);
        $image = $data['image'] ?? null;

        if (! $image instanceof UploadedFile) {
            $photo->fill(['caption' => $caption, 'sort_order' => $sortOrder])->save();

            return $photo;
        }

        $oldPath = $photo->path;
        $newPath = $this->images->handle($image, $photo->album->directory(), 'image');

        return $this->saveWithFiles([$newPath], [$oldPath], function () use ($photo, $caption, $sortOrder, $newPath): Photo {
            $photo->fill(['caption' => $caption, 'sort_order' => $sortOrder, 'path' => $newPath])->save();

            return $photo;
        });
    }
}
