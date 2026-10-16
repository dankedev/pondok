<?php

namespace App\Actions\Konten;

use App\Models\StaticPage;
use Illuminate\Support\HtmlString;

/**
 * Halaman statis portal yang terbit. Halaman `kontak` hanya tersedia bila profil
 * lembaga punya data kontak (aturan Kontak F037). Body disanitasi ulang saat render.
 * Profil dan visi-misi saling menautkan bila halaman tujuannya terbit.
 */
class ShowPublicStaticPage
{
    /** Tautan "terkait" antarhalaman sistem. */
    private const RELATED = ['profil' => 'visi-misi', 'visi-misi' => 'profil'];

    public function __construct(
        private readonly SanitizeContentHtml $sanitize,
        private readonly GetSchoolProfile $schoolProfile,
    ) {}

    /**
     * @return array{page: StaticPage, bodyHtml: ?HtmlString, school: array<string, mixed>, isContact: bool, related: ?array{url: string, label: string}}
     */
    public function handle(string $slug): array
    {
        $page = StaticPage::query()->published()->where('slug', $slug)->first();
        abort_if($page === null, 404);

        $school = $this->schoolProfile->handle();
        $isContact = $page->slug === 'kontak';
        abort_if($isContact && ! GetSchoolProfile::hasContactData($school), 404);

        $body = $this->sanitize->handle($page->body);

        return [
            'page' => $page,
            'bodyHtml' => $this->sanitize->isBlank($body) ? null : new HtmlString($body),
            'school' => $school,
            'isContact' => $isContact,
            'related' => $this->related($page),
        ];
    }

    /**
     * @return ?array{url: string, label: string}
     */
    private function related(StaticPage $page): ?array
    {
        $slug = self::RELATED[$page->slug] ?? null;
        $target = $slug === null ? null : StaticPage::query()->published()->where('slug', $slug)->first(['id', 'slug', 'title']);

        return $target === null ? null : ['url' => route('portal.pages.show', $target->slug), 'label' => $target->title];
    }
}
