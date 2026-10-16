<?php

namespace App\Actions\Konten;

use App\Models\Post;

/**
 * Pulihkan post dari tempat sampah.
 */
class RestorePost
{
    public function handle(Post $post): void
    {
        $post->restore();
    }
}
