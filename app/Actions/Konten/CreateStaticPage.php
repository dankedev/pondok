<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\ValidatesStaticPageInput;
use App\Models\StaticPage;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Buat halaman bebas (F037). Slug kosong dibuat dari judul; status bawaan `draft`,
 * template bawaan `default`; template `psb-info` memaksa slug `info-psb`. Satu instalasi maksimal `StaticPage::MAX_PAGES` halaman.
 */
class CreateStaticPage
{
    use ValidatesStaticPageInput;

    public function __construct(
        private readonly SanitizeContentHtml $sanitizer,
        private readonly ResolveStaticPageSlug $slugs,
    ) {}

    /**
     * @param  array{title?: mixed, slug?: mixed, body?: mixed, status?: mixed, template?: mixed}  $data
     */
    public function handle(array $data): StaticPage
    {
        if (StaticPage::query()->count() >= StaticPage::MAX_PAGES) {
            $this->fail('title', 'page_limit_reached', ['max' => StaticPage::MAX_PAGES]);
        }

        $title = $this->requiredText($data['title'] ?? null, 'title', StaticPage::TITLE_MAX_LENGTH);
        $template = $this->pageTemplate($data['template'] ?? StaticPage::TEMPLATE_DEFAULT);
        $slug = $template === StaticPage::TEMPLATE_PSB_INFO
            ? $this->psbInfoSlug()
            : $this->slugs->handle($title, $data['slug'] ?? null);
        $body = $this->pageBody($data['body'] ?? null, $this->sanitizer);
        $status = $this->pageStatus($data['status'] ?? StaticPage::STATUS_DRAFT);

        $page = new StaticPage;

        try {
            $page->fill(['title' => $title, 'slug' => $slug, 'body' => $body, 'status' => $status, 'template' => $template])->save();
        } catch (UniqueConstraintViolationException) {
            $this->fail('slug', 'slug_taken');
        }

        return $page;
    }
}
