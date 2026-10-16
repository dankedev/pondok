<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\SavesProfileSettings;
use App\Actions\Konten\Concerns\ValidatesPublicUrls;
use App\Models\User;

/**
 * Simpan footer portal (halaman Footer): kutipan (Arab, terjemah, sumber),
 * deskripsi lembaga, dan tautan cepat. Tautan berupa URL absolut aman atau path
 * internal aman. Kosong → null.
 */
class UpdatePortalFooter
{
    use SavesProfileSettings, ValidatesPublicUrls;

    public const MAX_LINKS = 8;

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor): void
    {
        $this->saveProfileSettings([
            'portal.footer_quote_arabic' => $this->optionalText($data['footer_quote_arabic'] ?? null, 'footer_quote_arabic', 1000),
            'portal.footer_quote_translation' => $this->optionalText($data['footer_quote_translation'] ?? null, 'footer_quote_translation', 1000),
            'portal.footer_quote_source' => $this->optionalText($data['footer_quote_source'] ?? null, 'footer_quote_source', 100),
            'portal.footer_description' => $this->optionalText($data['footer_description'] ?? null, 'footer_description', 500),
            'portal.footer_links' => $this->links($data['footer_links'] ?? null) ?: null,
        ], 'portal_footer_updated', $actor);
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    private function links(mixed $value): array
    {
        $links = [];

        foreach ($this->listInput($value, 'footer_links', self::MAX_LINKS) as $index => $item) {
            $field = "footer_links.{$index}";
            $label = $this->requiredText($item['label'] ?? null, "{$field}.label", 60, 'label_required');
            $url = is_scalar($item['url'] ?? null) ? (string) $item['url'] : '';

            if (mb_strlen($url) > 255) {
                $this->fail("{$field}.url", 'too_long', ['max' => 255]);
            }

            if (! $this->isSafeAbsoluteUrl($url) && ! $this->isSafeInternalPath($url)) {
                $this->fail("{$field}.url", 'link_invalid');
            }

            $links[] = ['label' => $label, 'url' => $url];
        }

        return $links;
    }
}
