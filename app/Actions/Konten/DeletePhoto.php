<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\ReplacesStoredFiles;
use App\Models\Photo;

/**
 * Hapus foto; berkasnya dihapus dari disk `public` setelah commit.
 */
class DeletePhoto
{
    use ReplacesStoredFiles;

    public function handle(Photo $photo): void
    {
        $this->saveWithFiles([], [$photo->path], fn () => $photo->delete());
    }
}
