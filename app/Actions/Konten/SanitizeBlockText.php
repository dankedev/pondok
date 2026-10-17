<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\ValidatesPublicUrls;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\UrlAttributeSanitizer;

/**
 * Sanitasi "teks terbatas" blok konten (F045): hanya `<p>`, `<br>`, `<strong>`, dan `<a href>`.
 * Tag lain dibuang tetapi teksnya dipertahankan (`script` dibuang beserta isinya).
 * `href` hanya absolut http(s) atau path internal yang diawali tepat satu `/` (aturan
 * `ValidatesPublicUrls`); selain itu atribut dibuang. Setiap tautan diberi `rel="noopener noreferrer"`.
 * Dipakai saat simpan dan saat baca blok.
 */
class SanitizeBlockText implements AttributeSanitizerInterface
{
    use ValidatesPublicUrls;

    private ?HtmlSanitizer $sanitizer = null;

    public function handle(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $this->sanitizer ??= new HtmlSanitizer($this->config());

        // `=` dikodekan Symfony sebagai `&#61;`; aman dikembalikan karena atribut selalu berkutip (query string tetap terbaca).
        return trim(str_replace(['<br />', '&#61;'], ['<br>', '='], $this->sanitizer->sanitize($html)));
    }

    /**
     * Pemeriksa URL bawaan Symfony diganti aturan sendiri (ia mengubah huruf skema dan menerima path relatif apa pun).
     */
    private function config(): HtmlSanitizerConfig
    {
        $config = new HtmlSanitizerConfig;

        foreach ($config->getAttributeSanitizers() as $sanitizer) {
            if ($sanitizer instanceof UrlAttributeSanitizer) {
                $config = $config->withoutAttributeSanitizer($sanitizer);
            }
        }

        return $config
            ->defaultAction(HtmlSanitizerAction::Block)
            ->dropElement('script')
            ->dropElement('style')
            ->dropElement('template')
            ->allowElement('p')
            ->allowElement('br')
            ->allowElement('strong')
            ->allowElement('a', ['href'])
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->withAttributeSanitizer($this);
    }

    public function getSupportedElements(): ?array
    {
        return ['a'];
    }

    public function getSupportedAttributes(): ?array
    {
        return ['href'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $url = trim($value);

        return $this->isSafeAbsoluteUrl($url) || $this->isSafeInternalPath($url) ? $url : null;
    }
}
