<?php

namespace App\Actions\Konten;

use App\Models\Video;

/**
 * Hapus video permanen (tidak ada berkas).
 */
class DeleteVideo
{
    public function handle(Video $video): void
    {
        $video->delete();
    }
}
