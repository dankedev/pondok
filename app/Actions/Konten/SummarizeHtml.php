<?php

namespace App\Actions\Konten;

use Illuminate\Support\Str;

/**
 * Ringkasan teks polos dari HTML isi (kartu berita, meta description, cuplikan profil).
 * Tag blok dipisah spasi agar teks antar-blok tidak menempel; tag inline tidak.
 * Hasilnya teks polos yang tetap harus di-escape oleh view.
 */
class SummarizeHtml
{
    private const BLOCK_TAG_PATTERN = '/<\/?(?:p|div|br|li|h[1-6]|tr|td|th|blockquote|section|article|figcaption|dd|dt)\b[^>]*>/i';

    public function handle(?string $html, int $limit = 160): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        $text = (string) preg_replace(self::BLOCK_TAG_PATTERN, ' $0 ', $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));

        return Str::limit($text, $limit, '…');
    }
}
