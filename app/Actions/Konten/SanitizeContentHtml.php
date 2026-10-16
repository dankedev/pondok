<?php

namespace App\Actions\Konten;

use Illuminate\Support\Str;

/**
 * Sanitasi HTML isi berita dan halaman statis (anti-XSS) dengan sanitizer bawaan
 * Filament (`Str::sanitizeHtml`, berbasis symfony/html-sanitizer).
 *
 * Sanitizer membuang skrip, iframe, atribut `on*`, dan tautan `javascript:`/`data:`,
 * tetapi mengizinkan `data:` pada media serta atribut `style`/`class`. Pemanggil
 * menolak media non-http(s) lewat `hasDisallowedMediaSource()` dan wajib memeriksa
 * `strlen()` terhadap `MAX_INPUT_BYTES` sebelum memanggil, karena sanitizer
 * memotong masukan yang lebih panjang tanpa galat.
 */
class SanitizeContentHtml
{
    /** Batas masukan sanitizer Filament, dalam byte (bukan karakter). */
    public const MAX_INPUT_BYTES = 500000;

    private const ALLOWED_MEDIA_SCHEMES = ['http', 'https'];

    public function handle(?string $html): string
    {
        return Str::sanitizeHtml($html ?? '');
    }

    /**
     * Ada `src`, `srcset`, atau `poster` dengan skema selain http/https (mis. `data:`) di HTML hasil sanitasi.
     * Atribut hasil sanitasi selalu berbentuk `nama="nilai"`, dan teks biasa sudah di-escape.
     */
    public function hasDisallowedMediaSource(string $sanitized): bool
    {
        preg_match_all('/\s(src|srcset|poster)="([^"]*)"/i', $sanitized, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $attribute, $value]) {
            $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5));
            $urls = strtolower($attribute) === 'srcset'
                ? array_map(fn (string $candidate): string => (string) strtok(trim($candidate), " \t\n\r"), explode(',', $value))
                : [$value];

            foreach ($urls as $url) {
                if (! $this->isAllowedMediaUrl($url)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Tanpa teks dan tanpa gambar (mis. `<p> </p>`, `<p><br></p>`, `&nbsp;`).
     */
    public function isBlank(string $html): bool
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        $text = trim(str_replace("\u{00A0}", ' ', $text));

        return $text === '' && stripos($html, '<img') === false;
    }

    private function isAllowedMediaUrl(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        // URL yang tidak bisa diurai dianggap tidak aman.
        if ($scheme === false) {
            return false;
        }

        return $scheme === null || in_array(strtolower($scheme), self::ALLOWED_MEDIA_SCHEMES, true);
    }
}
