<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\WritesInstalledThemes;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;
use SodiumException;

/**
 * Pasang satu tema resmi dari katalog (F103): `handle(entri, byte, pelaku)`. Katalog dan unduhan tidak dipercaya; yang dipercaya
 * hanya tanda tangan Ed25519 atas byte persis `theme.json` (kunci publik dari `pondok.theme_public_keys` lewat `key_id`).
 *
 * Urutan: slug, ukuran, sha256, tanda tangan, lalu (di bawah kunci tulis slug) staging, `ValidatePortalTheme`, versi manifest = entri,
 * versi harus lebih tinggi dari tema terpasang yang valid, dan ganti atomik: salin `theme.json` lama ke `previous.json.tmp`,
 * rename staging ke `theme.json`, rename `.tmp` ke `previous.json`. Invarian: `theme.json` selalu utuh dan valid, `previous.json`
 * selalu utuh atau tidak ada, sisa `.tmp` dan staging dibersihkan. Penolakan = `InvalidArgumentException` berpesan Indonesia. Log `portal_theme_installed` ditulis hanya bila ada pelaku.
 */
class InstallPortalTheme
{
    use WritesInstalledThemes;

    public function __construct(private readonly ValidatePortalTheme $validate) {}

    /**
     * @param  array<string, mixed>  $entry  entri katalog
     * @param  string  $bytes  isi `theme.json` hasil unduhan
     *
     * @throws InvalidArgumentException
     */
    public function handle(array $entry, string $bytes, ?User $actor = null): void
    {
        $slug = $this->assertThemeSlug($entry['slug'] ?? null);

        if (($entry['size'] ?? null) !== strlen($bytes)) {
            $this->reject('size_mismatch');
        }

        if (! is_string($entry['sha256'] ?? null) || ! hash_equals(hash('sha256', $bytes), strtolower($entry['sha256']))) {
            $this->reject('sha256_mismatch');
        }

        $this->assertSignature($entry, $bytes);

        $version = $this->withWriteLock($slug, function () use ($slug, $entry, $bytes): string {
            $manifest = $this->validate->handle($this->stage($slug, $bytes));

            if ($manifest['version'] !== ($entry['version'] ?? null)) {
                $this->reject('version_mismatch');
            }

            $installed = $this->installedVersion($slug);

            if ($installed !== null && version_compare($manifest['version'], $installed, '<=')) {
                $this->reject('version_not_newer', ['version' => $manifest['version'], 'installed' => $installed]);
            }

            $this->swap($slug);

            return $manifest['version'];
        });

        // Pemasangan tanpa pelaku (pemanggil non-pengguna) tidak dicatat sebagai tindakan pengguna.
        if ($actor !== null) {
            activity('konten')
                ->causedBy($actor)
                ->event('installed')
                ->withProperties(['slug' => $slug, 'version' => $version])
                ->log('portal_theme_installed');
        }
    }

    /**
     * Versi tema terpasang yang valid; null bila belum terpasang atau rusak (rusak boleh dipasang ulang).
     */
    private function installedVersion(string $slug): ?string
    {
        $path = $this->themeRoot()."/{$slug}/theme.json";

        try {
            return is_file($path) ? $this->validate->handle($path)['version'] : null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function swap(string $slug): void
    {
        $directory = $this->themeRoot()."/{$slug}";
        $theme = "{$directory}/theme.json";
        $temporary = "{$directory}/previous.json.tmp";
        $hasOld = is_file($theme);

        if (! $hasOld && ! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->reject('write_failed');
        }

        try {
            if ($hasOld && ! @copy($theme, $temporary)) {
                $this->reject('write_failed');
            }

            if (! @rename($this->stagingDirectory($slug).'/theme.json', $theme)) {
                $this->reject('write_failed');
            }

            if ($hasOld && ! @rename($temporary, "{$directory}/previous.json")) {
                $this->reject('write_failed');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function assertSignature(array $entry, string $bytes): void
    {
        $keyId = $entry['key_id'] ?? null;
        $keys = (array) config('pondok.theme_public_keys');

        if (! is_string($keyId) || ! array_key_exists($keyId, $keys)) {
            $this->reject('key_unknown', ['key' => Str::limit(is_string($keyId) ? $keyId : '', 20)]);
        }

        $encodedKey = is_string($keys[$keyId]) ? trim($keys[$keyId]) : '';

        if ($encodedKey === '') {
            $this->reject('key_not_configured');
        }

        $publicKey = base64_decode($encodedKey, true);

        if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            $this->reject('key_misconfigured');
        }

        $signature = is_string($entry['signature'] ?? null) ? base64_decode($entry['signature'], true) : false;

        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            $this->reject('signature_invalid');
        }

        try {
            $valid = sodium_crypto_sign_verify_detached($signature, $bytes, $publicKey);
        } catch (SodiumException) {
            $valid = false;
        }

        if (! $valid) {
            $this->reject('signature_invalid');
        }
    }
}
