<?php

namespace App\Actions\Konten;

use App\Models\Post;

/**
 * Pindahkan post ke tempat sampah (soft delete). Berkas sampul tetap ada agar post bisa dipulihkan.
 */
class DeletePost
{
    public function handle(Post $post): void
    {
        $post->delete();
    }
}
