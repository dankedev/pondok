<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\SavesProfileSettings;
use App\Models\User;

/**
 * Simpan footer portal generik (halaman Footer, F043): deskripsi lembaga, teks pendek
 * (judul opsional + isi, teks polos), dan saklar tampil kontak serta media sosial.
 * Tautan footer diatur lewat `UpdatePortalNavigation`. Teks kosong → null.
 */
class UpdatePortalFooter
{
    use SavesProfileSettings;

    public const DESCRIPTION_MAX_LENGTH = 500;

    public const TEXT_TITLE_MAX_LENGTH = 60;

    public const TEXT_BODY_MAX_LENGTH = 300;

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor): void
    {
        $this->saveProfileSettings([
            'portal.footer_description' => $this->optionalText($data['footer_description'] ?? null, 'footer_description', self::DESCRIPTION_MAX_LENGTH),
            'portal.footer_text' => $this->footerText($data['footer_text'] ?? null),
            'portal.footer_show_contacts' => $this->toggle($data['footer_show_contacts'] ?? null),
            'portal.footer_show_social' => $this->toggle($data['footer_show_social'] ?? null),
        ], 'portal_footer_updated', $actor);
    }

    /**
     * Isi kosong → null; judul tanpa isi ditolak.
     *
     * @return ?array{title: ?string, body: string}
     */
    private function footerText(mixed $value): ?array
    {
        $value = is_array($value) ? $value : [];
        $title = $this->plainText($value['title'] ?? null, 'footer_text.title', self::TEXT_TITLE_MAX_LENGTH);
        $body = $this->plainText($value['body'] ?? null, 'footer_text.body', self::TEXT_BODY_MAX_LENGTH);

        if ($body === null && $title !== null) {
            $this->fail('footer_text.body', 'footer_text_body_required');
        }

        return $body === null ? null : ['title' => $title, 'body' => $body];
    }

    /**
     * Saklar tampil; tidak diisi → true.
     */
    private function toggle(mixed $value): bool
    {
        return $value === null || filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
