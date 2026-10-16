<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\ValidatesContentInput;
use App\Models\StaticPage;

/**
 * Ubah judul dan isi halaman statis. Slug tetap; isi boleh kosong.
 */
class UpdateStaticPage
{
    use ValidatesContentInput;

    public const TITLE_MAX_LENGTH = 255;

    public function __construct(private readonly SanitizeContentHtml $sanitizer) {}

    /**
     * @param  array{title?: mixed, body?: mixed}  $data
     */
    public function handle(StaticPage $page, array $data): StaticPage
    {
        $title = $this->requiredText($data['title'] ?? null, 'title', self::TITLE_MAX_LENGTH);
        $html = is_scalar($data['body'] ?? null) ? (string) $data['body'] : '';

        if (strlen($html) > SanitizeContentHtml::MAX_INPUT_BYTES) {
            $this->fail('body', 'too_long', ['max' => SanitizeContentHtml::MAX_INPUT_BYTES]);
        }

        $body = $this->sanitizer->handle($html);

        if ($this->sanitizer->hasDisallowedMediaSource($body)) {
            $this->fail('body', 'media_source_invalid');
        }

        $page->fill([
            'title' => $title,
            'body' => $this->sanitizer->isBlank($body) ? '' : $body,
        ])->save();

        return $page;
    }
}
