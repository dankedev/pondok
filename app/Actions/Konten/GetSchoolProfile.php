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
        'school.phone',
        'school.email',
        'school.map_url',
        'school.contacts',
        'school.social_links',
        'portal.hero_greeting',
        'portal.hero_title',
        'portal.hero_subtitle',
        'portal.highlights_heading',
        'portal.highlights',
        'portal.hero_album_id',
        'portal.profile_video_id',
        'portal.psb_video_id',
        'portal.footer_quote_arabic',
        'portal.footer_quote_translation',
        'portal.footer_quote_source',
        'portal.footer_description',
        'portal.footer_links',
    ];

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
            'phone' => $text('school.phone'),
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
            'hero' => [
                'greeting' => $text('portal.hero_greeting'),
                'title' => $text('portal.hero_title'),
                'subtitle' => $text('portal.hero_subtitle'),
            ],
            'highlights_heading' => $text('portal.highlights_heading'),
            'highlights' => $this->highlights($values['portal.highlights']),
            'hero_album_id' => $this->id($values['portal.hero_album_id']),
            'profile_video_id' => $this->id($values['portal.profile_video_id']),
            'psb_video_id' => $this->id($values['portal.psb_video_id']),
            'footer' => [
                'quote_arabic' => $text('portal.footer_quote_arabic'),
                'quote_translation' => $text('portal.footer_quote_translation'),
                'quote_source' => $text('portal.footer_quote_source'),
                'description' => $text('portal.footer_description'),
                'links' => $this->footerLinks($values['portal.footer_links']),
            ],
        ];
    }

    private function text(mixed $value): ?string
    {
        return is_scalar($value) && ! is_bool($value) && trim((string) $value) !== '' ? (string) $value : null;
    }

    private function url(?string $path): ?string
    {
        return $path === null ? null : Storage::disk(StorePublicImage::DISK)->url($path);
    }

    private function id(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);

        return $id === false || $id < 1 ? null : $id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /**
     * @return list<array{key: ?string, label: string, name: ?string, phone: string, is_whatsapp: bool}>
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
     * @return list<array{title: string, description: ?string}>
     */
    private function highlights(mixed $value): array
    {
        $highlights = [];

        foreach ($this->items($value) as $item) {
            $title = $this->text($item['title'] ?? null);

            if ($title !== null) {
                $highlights[] = ['title' => $title, 'description' => $this->text($item['description'] ?? null)];
            }
        }

        return $highlights;
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    private function footerLinks(mixed $value): array
    {
        $links = [];

        foreach ($this->items($value) as $item) {
            $label = $this->text($item['label'] ?? null);
            $url = $item['url'] ?? null;

            if ($label !== null && ($this->isSafeAbsoluteUrl($url) || $this->isSafeInternalPath($url))) {
                $links[] = ['label' => $label, 'url' => $url];
            }
        }

        return $links;
    }
}
