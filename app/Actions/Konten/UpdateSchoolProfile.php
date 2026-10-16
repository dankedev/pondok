<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\ReplacesStoredFiles;
use App\Actions\Konten\Concerns\ValidatesContentInput;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * Simpan profil sekolah ke `settings` (`school.*`).
 *
 * Semua field divalidasi sebelum menulis apa pun. Nomor telepon dinormalisasi
 * (tanpa spasi, `-`, `.`, kurung). Key kosong disimpan null. Logo baru
 * menggantikan yang lama (berkas lama dihapus setelah commit). Perubahan dicatat
 * di activity log `konten`, hanya key yang berubah.
 */
class UpdateSchoolProfile
{
    use ReplacesStoredFiles, ValidatesContentInput;

    public const LOGO_DIRECTORY = 'school';

    private const LIMITS = ['name' => 100, 'tagline' => 150, 'address' => 500, 'email' => 255, 'url' => 255];

    private const SOCIAL_FIELDS = ['instagram_url', 'facebook_url', 'youtube_url'];

    public function __construct(private readonly StorePublicImage $images) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor): void
    {
        $values = [
            'school.name' => $this->requiredText($data['name'] ?? null, 'name', self::LIMITS['name'], 'name_required'),
            'school.tagline' => $this->optionalText($data['tagline'] ?? null, 'tagline', self::LIMITS['tagline']),
            'school.address' => $this->optionalText($data['address'] ?? null, 'address', self::LIMITS['address']),
            'school.phone' => $this->phone($data['phone'] ?? null, 'phone'),
            'school.whatsapp' => $this->phone($data['whatsapp'] ?? null, 'whatsapp'),
            'school.email' => $this->email($data['email'] ?? null),
        ];

        foreach (self::SOCIAL_FIELDS as $field) {
            $values["school.{$field}"] = $this->url($data[$field] ?? null, $field);
        }

        $logo = $data['logo'] ?? null;
        $logoExtension = $logo === null ? null : $this->images->validate($logo, 'logo', StorePublicImage::LOGO_MAX_KILOBYTES);

        $oldLogo = Setting::get('school.logo_path');
        $oldLogo = is_string($oldLogo) && $oldLogo !== '' ? $oldLogo : null;
        $values['school.logo_path'] = $oldLogo;

        if ($logo instanceof UploadedFile && $logoExtension !== null) {
            $values['school.logo_path'] = $this->images->store($logo, self::LOGO_DIRECTORY, $logoExtension);
        } elseif ((bool) ($data['remove_logo'] ?? false)) {
            $values['school.logo_path'] = null;
        }

        $newLogo = $values['school.logo_path'];
        $changed = [];

        foreach ($values as $key => $value) {
            $old = Setting::get($key);

            if ($old !== $value) {
                $changed[$key] = ['old' => $old, 'new' => $value];
            }
        }

        $this->saveWithFiles(
            $newLogo !== $oldLogo && $newLogo !== null ? [$newLogo] : [],
            $newLogo !== $oldLogo ? [$oldLogo] : [],
            function () use ($values, $changed, $actor): void {
                foreach ($values as $key => $value) {
                    Setting::set($key, $value);
                }

                if ($changed !== []) {
                    activity('konten')
                        ->causedBy($actor)
                        ->event('updated')
                        ->withProperties(['changed' => $changed])
                        ->log('school_profile_updated');
                }
            },
        );
    }

    private function phone(mixed $value, string $field): ?string
    {
        $phone = is_scalar($value) ? str_replace([' ', '-', '.', '(', ')'], '', trim((string) $value)) : '';

        if ($phone === '') {
            return null;
        }

        if (preg_match('/^\+?[0-9]{8,15}$/', $phone) !== 1) {
            $this->fail($field, 'phone_invalid');
        }

        return $phone;
    }

    private function email(mixed $value): ?string
    {
        $email = $this->optionalText($value, 'email', self::LIMITS['email']);

        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->fail('email', 'email_invalid');
        }

        return $email;
    }

    private function url(mixed $value, string $field): ?string
    {
        $url = $this->optionalText($value, $field, self::LIMITS['url']);

        if ($url === null) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array($scheme, ['http', 'https'], true)) {
            $this->fail($field, 'url_invalid');
        }

        return $url;
    }
}
