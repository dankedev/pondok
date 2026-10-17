<?php

namespace App\Actions\Konten\Concerns;

use App\Actions\Konten\SanitizeContentHtml;
use App\Models\StaticPage;

/**
 * Bersama `CreateStaticPage` dan `UpdateStaticPage`: isi HTML disanitasi (isi kosong
 * disimpan `''`), media non-http(s) dan sematan non-YouTube ditolak, status harus
 * `draft` atau `terbit`, dan template harus salah satu `StaticPage::TEMPLATES`.
 */
trait ValidatesStaticPageInput
{
    use ValidatesContentInput;

    protected function pageBody(mixed $value, SanitizeContentHtml $sanitizer): string
    {
        $html = is_scalar($value) ? (string) $value : '';

        if (strlen($html) > SanitizeContentHtml::MAX_INPUT_BYTES) {
            $this->fail('body', 'too_long', ['max' => SanitizeContentHtml::MAX_INPUT_BYTES]);
        }

        if ($sanitizer->hasForeignEmbed($html)) {
            $this->fail('body', 'media_source_invalid');
        }

        $body = $sanitizer->handle($html);

        if ($sanitizer->hasDisallowedMediaSource($body)) {
            $this->fail('body', 'media_source_invalid');
        }

        return $sanitizer->isBlank($body) ? '' : $body;
    }

    protected function pageTemplate(mixed $value): string
    {
        if (! is_string($value) || ! in_array($value, StaticPage::TEMPLATES, true)) {
            $this->fail('template', 'template_invalid');
        }

        return $value;
    }

    protected function pageStatus(mixed $value): string
    {
        if (! is_string($value) || ! in_array($value, StaticPage::STATUSES, true)) {
            $this->fail('status', 'status_invalid');
        }

        return $value;
    }
}
