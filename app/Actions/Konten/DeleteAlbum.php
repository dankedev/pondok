<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\ReplacesStoredFiles;
use App\Models\Album;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Hapus album permanen beserta fotonya; berkas foto dan folder album dihapus
 * setelah commit. Kategori tidak tersentuh.
 */
class DeleteAlbum
{
    use ReplacesStoredFiles;

    public function handle(Album $album): void
    {
        $paths = $album->photos()->pluck('path')->all();
        $directory = $album->directory();

        $this->saveWithFiles([], $paths, function () use ($album, $directory): void {
            $album->photos()->delete();
            $album->delete();

            DB::afterCommit(fn () => Storage::disk(StorePublicImage::DISK)->deleteDirectory($directory));
        });
    }
}
