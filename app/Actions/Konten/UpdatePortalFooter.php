<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\SavesProfileSettings;
use App\Actions\Konten\Concerns\ValidatesPublicUrls;
use App\Models\StaticPage;
use App\Models\User;

/**
 * Simpan footer portal (halaman Footer): kutipan (Arab, terjemah, sumber),
 * deskripsi lembaga, dan tautan cepat. Tautan berupa URL absolut aman, path
 * internal aman, atau halaman situs (`page_id`, F037). Kosong → null.
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
     * Tautan URL `{label, url}` (label wajib) atau tautan halaman `{label: ?string, page_id}`
     * (label opsional, halaman boleh draf). Tepat satu dari `url` dan `page_id` harus diisi.
     *
     * @return list<array{label: string, url: string}|array{label: ?string, page_id: int}>
     */
    private function links(mixed $value): array
    {
        $links = [];
        $items = $this->listInput($value, 'footer_links', self::MAX_LINKS);
        $pageIds = null;

        foreach ($items as $index => $item) {
            $field = "footer_links.{$index}";
            $url = is_scalar($item['url'] ?? null) ? (string) $item['url'] : '';
            $pageId = $item['page_id'] ?? null;
            $hasPage = $pageId !== null && $pageId !== '';

            if ((trim($url) !== '') === $hasPage) {
                $this->fail($field, 'link_target_invalid');
            }

            if ($hasPage) {
                $pageIds ??= StaticPage::query()->pluck('id')->all();
                $id = filter_var($pageId, FILTER_VALIDATE_INT);

                if ($id === false || ! in_array($id, $pageIds, true)) {
                    $this->fail("{$field}.page_id", 'page_not_found');
                }

                $links[] = ['label' => $this->optionalText($item['label'] ?? null, "{$field}.label", 60), 'page_id' => $id];

                continue;
            }

            $label = $this->requiredText($item['label'] ?? null, "{$field}.label", 60, 'label_required');

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
