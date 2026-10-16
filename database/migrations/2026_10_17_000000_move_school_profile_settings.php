<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * F036: pindahkan profil sekolah lama ke profil lembaga generik (data saja).
 *
 * - `school.whatsapp` menjadi kontak WhatsApp di `school.contacts` (tanpa duplikat nomor);
 * - URL sosial lama yang aman ditambahkan ke `school.social_links` (tanpa duplikat URL);
 * - berkas logo cetak disalin menjadi logo desktop bila logo desktop kosong.
 *
 * Ditulis dengan `DB::table` agar tidak bergantung pada model di masa depan, jadi
 * nilai di-decode/encode JSON sendiri. Nilai rusak dianggap kosong, tanpa exception.
 */
return new class extends Migration
{
    private const LEGACY_SOCIAL = [
        'instagram' => 'school.instagram_url',
        'facebook' => 'school.facebook_url',
        'youtube' => 'school.youtube_url',
    ];

    private const DISK = 'public';

    public function up(): void
    {
        $contacts = $this->list('school.contacts');
        $contactsChanged = false;
        $whatsapp = $this->normalizePhone($this->read('school.whatsapp'));

        if ($whatsapp !== null) {
            $phones = array_map(fn (array $contact): ?string => $this->normalizePhone($contact['phone'] ?? null), $contacts);

            if (! in_array($whatsapp, $phones, true)) {
                $contacts[] = ['key' => $this->contactKey($contacts), 'label' => 'WhatsApp', 'name' => null, 'phone' => $whatsapp, 'is_whatsapp' => true];
                $contactsChanged = true;
            }
        }

        $socials = $this->list('school.social_links');
        $socialsChanged = false;

        foreach (self::LEGACY_SOCIAL as $platform => $key) {
            $url = $this->read($key);

            if (! $this->isSafeUrl($url) || in_array($url, array_column($socials, 'url'), true)) {
                continue;
            }

            $socials[] = ['platform' => $platform, 'label' => null, 'url' => $url];
            $socialsChanged = true;
        }

        $copiedLogo = $this->copyPrintLogo();

        try {
            DB::transaction(function () use ($contacts, $contactsChanged, $socials, $socialsChanged, $copiedLogo): void {
                if ($contactsChanged) {
                    $this->write('school.contacts', $contacts);
                }

                if ($socialsChanged) {
                    $this->write('school.social_links', $socials);
                }

                if ($copiedLogo !== null) {
                    $this->write('school.logo_desktop_path', $copiedLogo);
                }

                DB::table('settings')->whereIn('key', ['school.whatsapp', ...array_values(self::LEGACY_SOCIAL)])->delete();
            });
        } catch (Throwable $exception) {
            if ($copiedLogo !== null) {
                Storage::disk(self::DISK)->delete($copiedLogo);
            }

            throw $exception;
        }
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            foreach ($this->list('school.contacts') as $contact) {
                $phone = $this->normalizePhone($contact['phone'] ?? null);

                if (($contact['is_whatsapp'] ?? false) === true && $phone !== null) {
                    $this->write('school.whatsapp', $phone);
                    break;
                }
            }

            $socials = $this->list('school.social_links');

            foreach (self::LEGACY_SOCIAL as $platform => $key) {
                foreach ($socials as $link) {
                    if (($link['platform'] ?? null) === $platform && is_string($link['url'] ?? null) && $link['url'] !== '') {
                        $this->write($key, $link['url']);
                        break;
                    }
                }
            }
        });
    }

    private function copyPrintLogo(): ?string
    {
        $desktop = $this->read('school.logo_desktop_path');
        $print = $this->read('school.logo_path');

        if ((is_string($desktop) && $desktop !== '') || ! is_string($print) || $print === '') {
            return null;
        }

        try {
            $disk = Storage::disk(self::DISK);

            if (! $disk->exists($print)) {
                return null;
            }

            $extension = strtolower(pathinfo($print, PATHINFO_EXTENSION)) ?: 'png';
            $copy = 'school/'.Str::uuid().'.'.$extension;

            return $disk->copy($print, $copy) ? $copy : null;
        } catch (Throwable) {
            // Disk tidak bisa ditulis saat deploy: lewati, admin dapat mengunggah ulang.
            return null;
        }
    }

    private function read(string $key): mixed
    {
        $value = DB::table('settings')->where('key', $key)->value('value');

        return is_string($value) ? json_decode($value, true) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function list(string $key): array
    {
        $value = $this->read($key);

        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    private function write(string $key, mixed $value): void
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (DB::table('settings')->where('key', $key)->exists()) {
            DB::table('settings')->where('key', $key)->update(['value' => $encoded, 'updated_at' => now()]);

            return;
        }

        DB::table('settings')->insert(['key' => $key, 'value' => $encoded, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function normalizePhone(mixed $value): ?string
    {
        $phone = is_scalar($value) ? str_replace([' ', '-', '.', '(', ')'], '', trim((string) $value)) : '';

        return $phone === '' ? null : $phone;
    }

    private function isSafeUrl(mixed $url): bool
    {
        return is_string($url)
            && preg_match('/[\x00-\x20\x7F]/', $url) !== 1
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            && (string) parse_url($url, PHP_URL_HOST) !== '';
    }

    /**
     * @param  list<array<string, mixed>>  $contacts
     */
    private function contactKey(array $contacts): string
    {
        $used = array_column($contacts, 'key');

        do {
            $key = strtolower(Str::random(8));
        } while (in_array($key, $used, true) || preg_match('/^[a-z0-9]{8}$/', $key) !== 1);

        return $key;
    }
};
