<?php

namespace App\Actions\Konten;

use App\Models\StaticPage;
use Illuminate\Support\HtmlString;

/**
 * Halaman statis portal yang terbit. Halaman bertemplate `contact` hanya tersedia
 * bila profil lembaga punya data kontak (aturan Kontak F037, F048). Body disanitasi ulang
 * saat render.
 */
class ShowPublicStaticPage
{
    public function __construct(
        private readonly SanitizeContentHtml $sanitize,
        private readonly GetSchoolProfile $schoolProfile,
    ) {}

    /**
     * @return array{page: StaticPage, bodyHtml: ?HtmlString, school: array<string, mixed>, isContact: bool}
     */
    public function handle(string $slug): array
    {
        $page = StaticPage::query()->published()->where('slug', $slug)->first();
        abort_if($page === null, 404);

        $school = $this->schoolProfile->handle();
        $isContact = $page->isContact();
        abort_if($isContact && ! GetSchoolProfile::hasContactData($school), 404);

        $body = $this->sanitize->handle($page->body);

        return [
            'page' => $page,
            'bodyHtml' => $this->sanitize->isBlank($body) ? null : new HtmlString($body),
            'school' => $school,
            'isContact' => $isContact,
        ];
    }
}
