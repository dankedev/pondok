<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Konten\FindPublicPost;
use App\Actions\Konten\SanitizeContentHtml;
use App\Http\Controllers\Controller;
use Illuminate\Support\HtmlString;
use Illuminate\View\View;

class ShowPost extends Controller
{
    public function __invoke(string $slug, FindPublicPost $findPost, SanitizeContentHtml $sanitize): View
    {
        $post = $findPost->handle($slug);

        return view('portal.posts.show', [
            'post' => $post,
            // Sanitasi ulang saat render (kontrak F016): body di DB tidak dipercaya mentah.
            'bodyHtml' => new HtmlString($sanitize->handle($post->body)),
        ]);
    }
}
