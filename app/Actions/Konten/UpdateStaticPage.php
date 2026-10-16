<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\ValidatesStaticPageInput;
use App\Models\StaticPage;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Ubah judul, isi, dan (opsional) slug serta status halaman. Key `slug`/`status` yang
 * tidak dikirim berarti tidak berubah. Slug halaman sistem terkunci; slug lain hanya
 * divalidasi bila berbeda dari yang tersimpan, sehingga halaman yang slug-nya
 * belakangan bentrok dengan route atau berkas baru tetap bisa diubah. Isi boleh kosong.
 */
class UpdateStaticPage
{
    use ValidatesStaticPageInput;

    public const TITLE_MAX_LENGTH = StaticPage::TITLE_MAX_LENGTH;

    public function __construct(
        private readonly SanitizeContentHtml $sanitizer,
        private readonly ResolveStaticPageSlug $slugs,
    ) {}

    /**
     * @param  array{title?: mixed, slug?: mixed, body?: mixed, status?: mixed}  $data
     */
    public function handle(StaticPage $page, array $data): StaticPage
    {
        $title = $this->requiredText($data['title'] ?? null, 'title', self::TITLE_MAX_LENGTH);
        $slug = $page->slug;

        if (array_key_exists('slug', $data) && ResolveStaticPageSlug::normalize($data['slug']) !== $page->slug) {
            if ($page->isSystem()) {
                $this->fail('slug', 'slug_locked');
            }

            $slug = $this->slugs->handle($title, $data['slug'], $page);
        }

        $body = $this->pageBody($data['body'] ?? null, $this->sanitizer);
        $status = array_key_exists('status', $data) ? $this->pageStatus($data['status']) : $page->status;

        try {
            $page->fill(['title' => $title, 'slug' => $slug, 'body' => $body, 'status' => $status])->save();
        } catch (UniqueConstraintViolationException) {
            $this->fail('slug', 'slug_taken');
        }

        return $page;
    }
}
