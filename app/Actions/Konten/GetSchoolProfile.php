<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\ValidatesPublicUrls;
use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * Baca profil lembaga dari `settings` (`school.*` dan `portal.*`) dengan satu query.
 *
 * Nama jatuh ke `config('app.name')`. Item daftar yang rusak dibuang per item, dan
 * URL yang tidak aman dibuang saat baca (melindungi dari data lama atau ubahan manual).
 */
class GetSchoolProfile
{
    use ValidatesPublicUrls;

    /** Satu sumber daftar key profil lembaga. */
    public const KEYS = [
        'school.name',
        'school.short_name',
        'school.arabic_name',
        'school.tagline',
        'school.logo_path',
        'school.logo_desktop_path',
        'school.logo_mobile_path',
        'school.favicon_path',
        'school.address',
        'school.email',
        'school.map_url',
        'school.contacts',
        'school.social_links',
        'portal.home_blocks',
        'portal.footer_description',
        'portal.footer_text',
        'portal.footer_show_contacts',
        'portal.footer_show_social',
        // Dibaca bersama agar `GetPortalNavigation` memakai cache tanpa query tambahan (F043).
        ...GetPortalNavigation::SETTING_KEYS,
    ];

    /** Field profil yang dihitung sebagai data kontak (aturan Kontak F037). */
    private const CONTACT_FIELDS = ['address', 'email', 'map_url'];

    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        $values = Setting::getMany(self::KEYS);
        $text = fn (string $key): ?string => $this->text($values[$key]);

        $logoPath = $text('school.logo_path');
        $desktopPath = $text('school.logo_desktop_path');
        $mobilePath = $text('school.logo_mobile_path');
        $faviconPath = $text('school.favicon_path');
        $mapUrl = $text('school.map_url');

        return [
            'name' => $text('school.name') ?? (string) config('app.name'),
            'short_name' => $text('school.short_name'),
            'arabic_name' => $text('school.arabic_name'),
            'tagline' => $text('school.tagline'),
            'address' => $text('school.address'),
            'email' => $text('school.email'),
            'map_url' => $this->isSafeAbsoluteUrl($mapUrl) ? $mapUrl : null,
            'logo_path' => $logoPath,
            'logo_url' => $this->url($logoPath),
            'logo_desktop_path' => $desktopPath,
            'logo_desktop_url' => $this->url($desktopPath),
            'logo_mobile_path' => $mobilePath,
            'logo_mobile_url' => $this->url($mobilePath),
            'favicon_path' => $faviconPath,
            'favicon_url' => $this->url($faviconPath),
            'contacts' => $this->contacts($values['school.contacts']),
            'social_links' => $this->socialLinks($values['school.social_links']),
            // Mentah: dibaca (dan disanitasi) hanya lewat `ParseContentBlocks`.
            'home_blocks' => $values['portal.home_blocks'],
            'footer' => [
                'description' => $text('portal.footer_description'),
                'text' => $this->footerText($values['portal.footer_text']),
                'show_contacts' => $values['portal.footer_show_contacts'] !== false,
                'show_social' => $values['portal.footer_show_social'] !== false,
            ],
        ];
    }

    /**
     * Profil punya minimal satu data kontak: halaman `kontak` hanya tersedia bila benar.
     *
     * @param  array<string, mixed>  $profile  keluaran `handle()`
     */
    public static function hasContactData(array $profile): bool
    {
        foreach (self::CONTACT_FIELDS as $field) {
            if (($profile[$field] ?? null) !== null) {
                return true;
            }
        }

        return ($profile['contacts'] ?? []) !== [];
    }

    private function text(mixed $value): ?string
    {
        return is_scalar($value) && ! is_bool($value) && trim((string) $value) !== '' ? (string) $value : null;
    }

    private function url(?string $path): ?string
    {
        return $path === null ? null : Storage::disk(StorePublicImage::DISK)->url($path);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /**
     * @return list<array{key: ?string, label: string, name: ?string, phone: string, is_whatsapp: bool, is_print: bool}>
     */
    private function contacts(mixed $value): array
    {
        $contacts = [];

        foreach ($this->items($value) as $item) {
            $label = $this->text($item['label'] ?? null);
            $phone = $this->text($item['phone'] ?? null);

            if ($label === null || $phone === null) {
                continue;
            }

            $contacts[] = [
                'key' => $this->text($item['key'] ?? null),
                'label' => $label,
                'name' => $this->text($item['name'] ?? null),
                'phone' => $phone,
                'is_whatsapp' => (bool) ($item['is_whatsapp'] ?? true),
                'is_print' => ($item['is_print'] ?? false) === true,
            ];
        }

        return $contacts;
    }

    /**
     * @return list<array{platform: string, label: ?string, url: string}>
     */
    private function socialLinks(mixed $value): array
    {
        $links = [];

        foreach ($this->items($value) as $item) {
            $platform = $item['platform'] ?? null;
            $label = $this->text($item['label'] ?? null);
            $url = $item['url'] ?? null;

            if (! in_array($platform, UpdateSchoolContacts::PLATFORMS, true)
                || ! $this->isSafeAbsoluteUrl($url)
                || ($platform === 'lainnya' && $label === null)) {
                continue;
            }

            $links[] = ['platform' => $platform, 'label' => $label, 'url' => $url];
        }

        return $links;
    }

    /**
     * Teks pendek footer: `null` bila isi kosong atau nilai rusak; judul opsional.
     *
     * @return ?array{title: ?string, body: string}
     */
    private function footerText(mixed $value): ?array
    {
        $body = is_array($value) ? $this->text($value['body'] ?? null) : null;

        return $body === null ? null : ['title' => $this->text($value['title'] ?? null), 'body' => $body];
    }
}
