<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\SavesProfileSettings;
use App\Actions\Konten\Concerns\ValidatesPublicUrls;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Simpan kontak lembaga (halaman Kontak & sosial): alamat, email, tautan peta, daftar
 * kontak berlabel, dan media sosial. Daftar disimpan utuh sesuai urutan input;
 * daftar kosong disimpan null.
 *
 * Paling banyak satu kontak bertanda `is_print`: nomornya dicetak di kop surat dan
 * kuitansi (`GetPrintIdentity`).
 *
 * Key kontak (8 karakter `[a-z0-9]`) dibuat untuk item baru dan dipertahankan untuk
 * item lama yang dikirim ulang; menjadi rujukan stabil kontak PSB. Nomor telepon
 * dicatat apa adanya di log karena memang diterbitkan di portal.
 */
class UpdateSchoolContacts
{
    use SavesProfileSettings, ValidatesPublicUrls;

    public const PLATFORMS = ['instagram', 'facebook', 'youtube', 'tiktok', 'telegram', 'whatsapp_channel', 'x', 'website', 'lainnya'];

    public const MAX_CONTACTS = 10;

    public const MAX_SOCIAL_LINKS = 10;

    public const MAX_ADDRESS = 500;

    public const MAX_EMAIL = 255;

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor): void
    {
        $this->saveProfileSettings([
            'school.address' => $this->optionalText($data['address'] ?? null, 'address', self::MAX_ADDRESS),
            'school.email' => $this->email($data['email'] ?? null),
            'school.map_url' => $this->absoluteUrl($data['map_url'] ?? null, 'map_url', 500, required: false),
            'school.contacts' => $this->contacts($data['contacts'] ?? null) ?: null,
            'school.social_links' => $this->socialLinks($data['social_links'] ?? null) ?: null,
        ], 'school_contacts_updated', $actor);
    }

    /**
     * @return list<array{key: string, label: string, name: ?string, phone: string, is_whatsapp: bool, is_print: bool}>
     */
    private function contacts(mixed $value): array
    {
        $items = $this->listInput($value, 'contacts', self::MAX_CONTACTS);
        $given = [];

        foreach ($items as $item) {
            $key = $item['key'] ?? null;

            if (is_string($key) && preg_match('/^[a-z0-9]{8}$/', $key) === 1) {
                if (in_array($key, $given, true)) {
                    $this->fail('contacts', 'contact_key_duplicate');
                }

                $given[] = $key;
            }
        }

        $contacts = [];
        $used = $given;

        foreach ($items as $index => $item) {
            $field = "contacts.{$index}";
            $label = $this->requiredText($item['label'] ?? null, "{$field}.label", 60, 'label_required');
            $name = $this->optionalText($item['name'] ?? null, "{$field}.name", 100);
            $phone = $this->phone($item['phone'] ?? null, "{$field}.phone") ?? $this->fail("{$field}.phone", 'phone_invalid');
            $key = $item['key'] ?? null;

            if (! is_string($key) || preg_match('/^[a-z0-9]{8}$/', $key) !== 1) {
                $key = $this->newKey($used);
                $used[] = $key;
            }

            $contacts[] = [
                'key' => $key,
                'label' => $label,
                'name' => $name,
                'phone' => $phone,
                'is_whatsapp' => filter_var($item['is_whatsapp'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'is_print' => filter_var($item['is_print'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        if (count(array_filter(array_column($contacts, 'is_print'))) > 1) {
            $this->fail('contacts', 'print_contact_multiple');
        }

        return $contacts;
    }

    /**
     * @return list<array{platform: string, label: ?string, url: string}>
     */
    private function socialLinks(mixed $value): array
    {
        $links = [];

        foreach ($this->listInput($value, 'social_links', self::MAX_SOCIAL_LINKS) as $index => $item) {
            $field = "social_links.{$index}";
            $platform = $item['platform'] ?? null;

            if (! in_array($platform, self::PLATFORMS, true)) {
                $this->fail("{$field}.platform", 'platform_invalid');
            }

            $label = $platform === 'lainnya'
                ? $this->requiredText($item['label'] ?? null, "{$field}.label", 60, 'label_required')
                : $this->optionalText($item['label'] ?? null, "{$field}.label", 60);

            $links[] = [
                'platform' => $platform,
                'label' => $label,
                'url' => $this->absoluteUrl($item['url'] ?? null, "{$field}.url", 255, required: true),
            ];
        }

        return $links;
    }

    private function email(mixed $value): ?string
    {
        $email = $this->optionalText($value, 'email', self::MAX_EMAIL);

        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->fail('email', 'email_invalid');
        }

        return $email;
    }

    private function absoluteUrl(mixed $value, string $field, int $max, bool $required): ?string
    {
        $url = is_scalar($value) ? (string) $value : '';

        if (trim($url) === '') {
            return $required ? $this->fail($field, 'url_invalid') : null;
        }

        $url = trim($url, " \u{00A0}");

        if (mb_strlen($url) > $max) {
            $this->fail($field, 'too_long', ['max' => $max]);
        }

        if (! $this->isSafeAbsoluteUrl($url)) {
            $this->fail($field, 'url_invalid');
        }

        return $url;
    }

    /**
     * @param  list<string>  $used
     */
    private function newKey(array $used): string
    {
        do {
            $key = strtolower(Str::random(8));
        } while (in_array($key, $used, true));

        return $key;
    }
}
