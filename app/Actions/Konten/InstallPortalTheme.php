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
 * versi harus lebih tinggi dari tema terpasang yang valid, berkas aset (F105: `$files` harus persis berkas yang dideklarasikan `assets`,
 * ditulis ke staging lalu diperiksa `ValidateThemePackage`), dan ganti atomik: tulis `assets/<hash12>/` (hash12 = 12 hex pertama sha256
 * byte `theme.json`), salin `theme.json` lama ke `previous.json.tmp`, rename staging ke `theme.json`, rename `.tmp` ke `previous.json`,
 * lalu pangkas folder aset selain milik `theme.json` dan `previous.json`. Invarian: `theme.json` selalu utuh dan valid, folder aset
 * miliknya selalu lengkap, `previous.json` selalu utuh atau tidak ada, sisa `.tmp` dan staging dibersihkan. Tema tanpa `assets` berperilaku
 * persis seperti F103. Penolakan = `InvalidArgumentException` berpesan Indonesia. Log `portal_theme_installed` ditulis hanya bila ada pelaku.
 */
class InstallPortalTheme
{
    use WritesInstalledThemes;

    public function __construct(private readonly ValidatePortalTheme $validate, private readonly ValidateThemePackage $package) {}

    /**
     * @param  array<string, mixed>  $entry  entri katalog
     * @param  string  $bytes  isi `theme.json` hasil unduhan
     * @param  array<string, string>  $files  path relatif (`theme.css`, `fonts/<nama>.woff2`) => isi byte; kosong untuk tema tanpa `assets`
     *
     * @throws InvalidArgumentException
     */
    public function handle(array $entry, string $bytes, ?User $actor = null, array $files = []): void
    {
        $slug = $this->verify($entry, $bytes);

        $version = $this->withWriteLock($slug, function () use ($slug, $entry, $bytes, $files): string {
            $manifest = $this->validate->handle($this->stage($slug, $bytes));

            if ($manifest['version'] !== ($entry['version'] ?? null)) {
                $this->reject('version_mismatch');
            }

            $installed = $this->installedVersion($slug);

            if ($installed !== null && version_compare($manifest['version'], $installed, '<=')) {
                $this->reject('version_not_newer', ['version' => $manifest['version'], 'installed' => $installed]);
            }

            $this->stageAssets($this->package, $slug, $manifest, $files);
            $this->swap($slug, $manifest);

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
     * Berkas aset yang dideklarasikan `theme.json` hasil unduhan (path => size terdeklarasi), setelah ukuran, sha256, tanda tangan, dan skema
     * lolos; dipakai job untuk tahu berkas apa yang harus diunduh. Tidak menulis apa pun ke tema terpasang.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, int>
     *
     * @throws InvalidArgumentException
     */
    public function declaredAssets(array $entry, string $bytes): array
    {
        $slug = $this->verify($entry, $bytes);

        $manifest = $this->withWriteLock($slug, fn (): array => $this->validate->handle($this->stage($slug, $bytes)));

        return ValidateThemePackage::sizes($manifest);
    }

    /**
     * Slug, ukuran, sha256, dan tanda tangan `theme.json` terhadap entri katalog.
     *
     * @param  array<string, mixed>  $entry
     */
    private function verify(array $entry, string $bytes): string
    {
        $slug = $this->assertThemeSlug($entry['slug'] ?? null);

        if (ResolveThemePath::isLocal($slug)) {
            $this->reject('local_slug');
        }

        if (($entry['size'] ?? null) !== strlen($bytes)) {
            $this->reject('size_mismatch');
        }

        if (! is_string($entry['sha256'] ?? null) || ! hash_equals(hash('sha256', $bytes), strtolower($entry['sha256']))) {
            $this->reject('sha256_mismatch');
        }

        $this->assertSignature($entry, $bytes);

        return $slug;
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
