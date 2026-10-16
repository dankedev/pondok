<?php

namespace App\Actions\Konten;

use App\Models\Post;

/**
 * Post terbit berdasarkan slug untuk portal. Draf, terjadwal, dan terhapus sama-sama 404.
 */
class FindPublicPost
{
    public function handle(string $slug): Post
    {
        $post = Post::query()->published()->where('slug', $slug)->with(['category', 'unit'])->first();

        abort_if($post === null, 404);

        return $post;
    }
}
