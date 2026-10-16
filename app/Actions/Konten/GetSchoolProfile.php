<?php

namespace App\Actions\Konten;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * Baca profil sekolah dari `settings` (`school.*`). Nama jatuh ke `config('app.name')`.
 */
class GetSchoolProfile
{
    /** Satu sumber daftar key profil sekolah. */
    public const KEYS = [
        'school.name',
        'school.tagline',
        'school.address',
        'school.phone',
        'school.whatsapp',
        'school.email',
        'school.instagram_url',
        'school.facebook_url',
        'school.youtube_url',
        'school.logo_path',
    ];

    /**
     * @return array{name: string, tagline: ?string, address: ?string, phone: ?string, whatsapp: ?string, email: ?string, instagram_url: ?string, facebook_url: ?string, youtube_url: ?string, logo_path: ?string, logo_url: ?string}
     */
    public function handle(): array
    {
        $profile = [];

        foreach (self::KEYS as $key) {
            $value = Setting::get($key);
            $profile[substr($key, strlen('school.'))] = is_scalar($value) && (string) $value !== '' ? (string) $value : null;
        }

        $profile['name'] ??= (string) config('app.name');
        $profile['logo_url'] = $profile['logo_path'] === null ? null : Storage::disk(StorePublicImage::DISK)->url($profile['logo_path']);

        return $profile;
    }
}
