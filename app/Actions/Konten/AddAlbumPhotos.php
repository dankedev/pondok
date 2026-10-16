<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\ReplacesStoredFiles;
use App\Actions\Konten\Concerns\ValidatesContentInput;
use App\Models\Album;
use App\Models\Photo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Unggah beberapa foto ke album. Semua berkas divalidasi dulu: satu tidak valid,
 * tidak ada yang disimpan. Nomor urut melanjutkan foto terakhir album.
 */
class AddAlbumPhotos
{
    use ReplacesStoredFiles, ValidatesContentInput;

    public const MAX_FILES_PER_UPLOAD = 20;

    public const CAPTION_MAX_LENGTH = 255;

    public function __construct(private readonly StorePublicImage $images) {}

    /**
     * @param  array<mixed>  $files
     * @return Collection<int, Photo>
     */
    public function handle(Album $album, array $files, ?string $caption = null): Collection
    {
        $files = array_values($files);

        if ($files === []) {
            $this->fail('photos', 'photos_required');
        }

        if (count($files) > self::MAX_FILES_PER_UPLOAD) {
            $this->fail('photos', 'photos_too_many', ['max' => self::MAX_FILES_PER_UPLOAD]);
        }

        $extensions = array_map(fn (mixed $file): string => $this->images->validate($file, 'photos'), $files);
        $caption = $this->optionalText($caption, 'caption', self::CAPTION_MAX_LENGTH);

        $paths = [];

        try {
            foreach ($files as $index => $file) {
                /** @var UploadedFile $file */
                $paths[] = $this->images->store($file, $album->directory(), $extensions[$index]);
            }
        } catch (Throwable $exception) {
            $this->images->delete(...$paths);

            throw $exception;
        }

        return $this->saveWithFiles($paths, [], function () use ($album, $paths, $caption): Collection {
            $next = (int) $album->photos()->max('sort_order') + 1;

            return collect($paths)->map(fn (string $path, int $index): Photo => $album->photos()->create([
                'path' => $path,
                'caption' => $caption,
                'sort_order' => min($next + $index, Photo::SORT_ORDER_MAX),
            ]));
        });
    }
}
