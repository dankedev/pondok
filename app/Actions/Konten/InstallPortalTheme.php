<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\WritesInstalledThemes;
use App\Models\User;
use Illuminate\Support\Facades\File;
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

            $this->stageAssets($slug, $manifest, $files);
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

    /**
     * Tulis berkas aset ke staging (hanya path yang dideklarasikan manifest) lalu periksa paketnya; tema tanpa `assets` tidak boleh membawa berkas.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, string>  $files
     */
    private function stageAssets(string $slug, array $manifest, array $files): void
    {
        $declared = ValidateThemePackage::paths($manifest);

        foreach (array_diff(array_keys($files), $declared) as $path) {
            $this->reject('pondok.theme_assets.file_extra', ['path' => Str::limit((string) $path, 60)]);
        }

        foreach (array_diff($declared, array_keys($files)) as $path) {
            $this->reject('pondok.theme_assets.file_missing', ['path' => $path]);
        }

        if (! isset($manifest['assets'])) {
            return;
        }

        $directory = $this->stagingDirectory($slug);

        foreach ($files as $path => $content) {
            if (! is_string($content) || (! is_dir(dirname("{$directory}/{$path}")) && ! @mkdir(dirname("{$directory}/{$path}"), 0755, true))
                || @file_put_contents("{$directory}/{$path}", $content) !== strlen($content)) {
                $this->reject('write_failed');
            }
        }

        $this->package->handle($directory, $manifest);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function swap(string $slug, array $manifest): void
    {
        $directory = $this->themeRoot()."/{$slug}";
        $theme = "{$directory}/theme.json";
        $temporary = "{$directory}/previous.json.tmp";
        $staging = $this->stagingDirectory($slug);
        $hasOld = is_file($theme);
        $target = isset($manifest['assets']) ? "{$directory}/assets/".$this->assetHash("{$staging}/theme.json") : null;
        $created = false;
        $swapped = false;

        if (is_link($directory)) {
            $this->reject('write_failed');
        }

        if (! $hasOld && ! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->reject('write_failed');
        }

        try {
            if ($target !== null) {
                $created = $this->placeAssets($staging, $target, [$this->assetHash($theme), $this->assetHash("{$directory}/previous.json")]);
            }

            if ($hasOld && ! @copy($theme, $temporary)) {
                $this->reject('write_failed');
            }

            if (! @rename("{$staging}/theme.json", $theme)) {
                $this->reject('write_failed');
            }

            $swapped = true;

            if ($hasOld && ! @rename($temporary, "{$directory}/previous.json")) {
                $this->reject('write_failed');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }

            // Gagal sebelum `theme.json` berganti: folder aset baru tak dimiliki siapa pun, buang.
            if ($created && ! $swapped) {
                File::deleteDirectory((string) $target);
            }
        }

        $this->pruneAssets($slug);
    }

    /**
     * Pindahkan `theme.css` dan `fonts/` dari staging ke `$target`. Folder `$target` yang sudah ada dan milik `theme.json`/`previous.json`
     * aktif (hash sama) sudah lengkap dan dibiarkan; yang bukan milik siapa pun (sisa pemasangan gagal) dihapus lalu diganti.
     *
     * @param  list<?string>  $owned
     * @return bool true bila folder baru dibuat
     */
    private function placeAssets(string $staging, string $target, array $owned): bool
    {
        if (is_dir($target) && in_array(basename($target), $owned, true)) {
            return false;
        }

        if (is_link($target) || (file_exists($target) && ! is_dir($target))) {
            @unlink($target);
        }

        File::deleteDirectory($target);

        if (! is_dir(dirname($target)) && ! @mkdir(dirname($target), 0755, true) && ! is_dir(dirname($target))) {
            $this->reject('write_failed');
        }

        if (! @mkdir($target, 0755)) {
            $this->reject('write_failed');
        }

        foreach (['theme.css', 'fonts'] as $name) {
            if (file_exists("{$staging}/{$name}") && ! @rename("{$staging}/{$name}", "{$target}/{$name}")) {
                File::deleteDirectory($target);
                $this->reject('write_failed');
            }
        }

        return true;
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
