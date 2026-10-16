<?php

namespace App\Actions\Konten;

use App\Models\StaticPage;
use Illuminate\Support\HtmlString;

/**
 * Halaman statis portal (profil, visi-misi, kontak). Body disanitasi ulang saat render.
 */
class ShowPublicStaticPage
{
    public function __construct(
        private readonly SanitizeContentHtml $sanitize,
        private readonly GetSchoolProfile $schoolProfile,
    ) {}

    /**
     * @return array{page: StaticPage, bodyHtml: ?HtmlString, school: array<string, ?string>}
     */
    public function handle(string $slug): array
    {
        abort_unless(in_array($slug, StaticPage::SLUGS, true), 404);

        $page = StaticPage::findBySlug($slug);
        abort_if($page === null, 404);

        $body = $this->sanitize->handle($page->body);

        return [
            'page' => $page,
            'bodyHtml' => $this->sanitize->isBlank($body) ? null : new HtmlString($body),
            'school' => $this->schoolProfile->handle(),
        ];
    }
}
