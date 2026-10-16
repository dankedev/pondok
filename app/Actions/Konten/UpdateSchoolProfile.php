<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\SavesProfileSettings;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * Simpan identitas lembaga (halaman Identitas): nama, nama singkat, nama Arab,
 * slogan, alamat, telepon kantor, email, serta logo cetak, logo desktop, logo
 * mobile, dan favicon.
 *
 * Semua field divalidasi sebelum menulis apa pun. Field teks yang tidak dikirim
 * disimpan null. Key lama `whatsapp` dan URL sosial diabaikan (dipindah ke
 * `UpdateSchoolContacts`). Gambar baru menggantikan yang lama (berkas lama dihapus
 * setelah commit). Log `school_profile_updated` hanya memuat key yang berubah.
 */
class UpdateSchoolProfile
{
    use SavesProfileSettings;

    public const LOGO_DIRECTORY = 'school';

    /** Field unggahan → key settings. */
    public const IMAGES = [
        'logo' => 'school.logo_path',
        'logo_desktop' => 'school.logo_desktop_path',
        'logo_mobile' => 'school.logo_mobile_path',
        'favicon' => 'school.favicon_path',
    ];

    private const LIMITS = ['name' => 100, 'short_name' => 30, 'arabic_name' => 150, 'tagline' => 150, 'address' => 500, 'email' => 255];

    public function __construct(private readonly StorePublicImage $images) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor): void
    {
        $values = [
            'school.name' => $this->requiredText($data['name'] ?? null, 'name', self::LIMITS['name'], 'name_required'),
            'school.short_name' => $this->optionalText($data['short_name'] ?? null, 'short_name', self::LIMITS['short_name']),
            'school.arabic_name' => $this->optionalText($data['arabic_name'] ?? null, 'arabic_name', self::LIMITS['arabic_name']),
            'school.tagline' => $this->optionalText($data['tagline'] ?? null, 'tagline', self::LIMITS['tagline']),
            'school.address' => $this->optionalText($data['address'] ?? null, 'address', self::LIMITS['address']),
            'school.phone' => $this->phone($data['phone'] ?? null, 'phone'),
            'school.email' => $this->email($data['email'] ?? null),
        ];

        $uploads = [];

        foreach (array_keys(self::IMAGES) as $field) {
            $file = $data[$field] ?? null;

            if ($file !== null) {
                $uploads[$field] = [$file, $field === 'favicon' ? $this->validateFavicon($file) : $this->images->validate($file, $field, StorePublicImage::LOGO_MAX_KILOBYTES)];
            }
        }

        $newPaths = [];
        $oldPaths = [];

        try {
            foreach (self::IMAGES as $field => $key) {
                $old = Setting::get($key);
                $old = is_string($old) && $old !== '' ? $old : null;
                $new = $old;

                if (isset($uploads[$field])) {
                    [$file, $extension] = $uploads[$field];
                    $new = $this->images->store($file, self::LOGO_DIRECTORY, $extension);
                    $newPaths[] = $new;
                } elseif ((bool) ($data["remove_{$field}"] ?? false)) {
                    $new = null;
                }

                if ($new !== $old) {
                    $oldPaths[] = $old;
                }

                $values[$key] = $new;
            }
        } catch (\Throwable $exception) {
            $this->images->delete(...$newPaths);

            throw $exception;
        }

        $this->saveProfileSettings($values, 'school_profile_updated', $actor, $newPaths, $oldPaths);
    }

    private function validateFavicon(mixed $file): string
    {
        if (! $file instanceof UploadedFile
            || $file->getMimeType() !== 'image/png'
            || (int) $file->getSize() > StorePublicImage::FAVICON_MAX_KILOBYTES * 1024) {
            $this->fail('favicon', 'favicon_invalid', ['max' => StorePublicImage::FAVICON_MAX_KILOBYTES]);
        }

        return 'png';
    }

    private function email(mixed $value): ?string
    {
        $email = $this->optionalText($value, 'email', self::LIMITS['email']);

        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->fail('email', 'email_invalid');
        }

        return $email;
    }
}
