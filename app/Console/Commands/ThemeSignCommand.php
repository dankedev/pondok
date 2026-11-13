<?php

namespace App\Console\Commands;

use App\Actions\Konten\ValidatePortalTheme;
use App\Actions\Konten\ValidateThemePackage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use SodiumException;
use stdClass;
use Symfony\Component\Console\Output\ConsoleOutputInterface;

/**
 * Untuk penerbit tema resmi (F103, F105): menandatangani `<slug>/theme.json` dengan kunci rahasia Ed25519 dari berkas
 * dan mencetak satu objek JSON entri katalog. Kunci rahasia tidak pernah dibaca dari config, env, atau argumen.
 *
 * `path` boleh folder paket (`<slug>/` berisi `theme.json`, `theme.css`, `fonts/`) atau `theme.json` langsung. Tema ber-`assets` diperiksa
 * `ValidateThemePackage` yang sama dengan pemasangan; pelanggaran = exit 1 tanpa entri. `--write-hashes` menulis ulang sha256 dan size
 * berkas aset di `theme.json` (hanya setelah seluruh paket lolos) sebelum menandatangani; ringkasan perubahan ke stderr agar stdout tetap JSON.
 */
#[Signature('pondok:theme-sign {path : Folder paket tema atau path <slug>/theme.json} {--write-hashes : Tulis ulang sha256 dan size berkas aset di theme.json sebelum menandatangani} {--secret-file= : Berkas berisi kunci rahasia Ed25519 (base64, 64 byte)} {--key-id= : Id kunci publik di config (k1/k2)} {--url= : URL publik berkas tema}')]
#[Description('Menandatangani theme.json dan mencetak entri katalog tema resmi')]
class ThemeSignCommand extends Command
{
    public function handle(ValidatePortalTheme $validate, ValidateThemePackage $package): int
    {
        try {
            $argument = rtrim((string) $this->argument('path'), '/');
            $path = is_dir($argument) ? "{$argument}/theme.json" : $argument;
            $original = is_file($path) ? (string) file_get_contents($path) : $this->reject('theme_missing');
            $bytes = $this->option('write-hashes') ? $this->withFreshHashes($original, $path, $changes) : $original;
            $manifest = $this->validated($validate, $package, $path, $bytes, $original);
            $keyId = (string) $this->option('key-id');
            $secret = $this->secretKey($keyId);
            $url = (string) $this->option('url');

            if ($url === '') {
                $this->reject('url_required');
            }

            if ($bytes !== $original) {
                file_put_contents($path, $bytes) === strlen($bytes) || $this->reject('write_failed');

                $this->report($changes);
            }

            $signature = sodium_crypto_sign_detached($bytes, $secret);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (SodiumException) {
            $this->error(__('pondok.theme_sign.secret_invalid'));

            return self::FAILURE;
        }

        $this->line((string) json_encode([
            'slug' => $manifest['slug'],
            'name' => $manifest['name'],
            'description' => $manifest['description'],
            'version' => $manifest['version'],
            'schema_version' => $manifest['schema_version'],
            'min_core' => $manifest['min_core'],
            'size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'url' => $url,
            'signature' => base64_encode($signature),
            'key_id' => $keyId,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    /**
     * Validasi skema dan (bila ber-`assets`) isi paket terhadap `$bytes`. Bila `$bytes` beda dari berkas, skema diperiksa pada salinan
     * sementara supaya `theme.json` asli tidak berubah sebelum seluruh paket lolos.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function validated(ValidatePortalTheme $validate, ValidateThemePackage $package, string $path, string $bytes, string $original): array
    {
        if ($bytes === $original) {
            $manifest = $validate->handle($path);
        } else {
            $temporary = sys_get_temp_dir().'/pondok-sign-'.bin2hex(random_bytes(6));
            $copy = "{$temporary}/".basename(dirname((string) realpath($path)));

            try {
                @mkdir($copy, 0700, true);
                file_put_contents("{$copy}/theme.json", $bytes);
                $manifest = $validate->handle("{$copy}/theme.json");
            } finally {
                File::deleteDirectory($temporary);
            }
        }

        if (isset($manifest['assets'])) {
            $package->handle(dirname($path), $manifest);
        }

        return $manifest;
    }

    /**
     * `theme.json` dengan sha256 dan size berkas aset yang ada diganti nilai sebenarnya. Berkas yang hilang atau berupa tautan dibiarkan
     * (pemeriksaan paket yang melaporkannya); manifest yang tak terbaca dibiarkan apa adanya (validator yang menolak).
     *
     * @param  list<string>|null  $changes
     */
    private function withFreshHashes(string $bytes, string $path, ?array &$changes): string
    {
        $manifest = json_decode($bytes, false, 64);

        if (! $manifest instanceof stdClass || ! isset($manifest->assets) || ! $manifest->assets instanceof stdClass) {
            return $bytes;
        }

        $files = [];

        foreach ([$manifest->assets->css ?? null, ...(is_array($manifest->assets->fonts ?? null) ? $manifest->assets->fonts : [])] as $file) {
            if ($file instanceof stdClass && is_string($file->path ?? null)) {
                $files[] = $file;
            }
        }

        $changes = [];

        foreach ($files as $file) {
            $full = dirname($path).'/'.$file->path;

            if (preg_match('#^(?:theme\.css|fonts/[a-z0-9][a-z0-9-]{0,40}\.woff2)\z#', $file->path) !== 1 || ! is_file($full) || is_link($full)) {
                continue;
            }

            $hash = (string) hash_file('sha256', $full);
            $size = (int) filesize($full);

            if (($file->sha256 ?? null) !== $hash || ($file->size ?? null) !== $size) {
                $changes[] = __('pondok.theme_sign.hash_updated', ['path' => $file->path, 'sha256' => $hash, 'size' => $size]);
                $file->sha256 = $hash;
                $file->size = $size;
            }
        }

        return $changes === [] ? $bytes : json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    /**
     * Ringkasan perubahan ke stderr (hanya di konsol sungguhan) supaya stdout tetap satu objek JSON yang bisa disalurkan ke berkas.
     *
     * @param  list<string>  $changes
     */
    private function report(array $changes): void
    {
        $output = $this->output->getOutput();

        if ($output instanceof ConsoleOutputInterface) {
            foreach ($changes as $change) {
                $output->getErrorOutput()->writeln($change);
            }
        }
    }

    /**
     * Kunci rahasia 64 byte dari berkas, yang harus berpasangan dengan kunci publik `key_id` di config.
     *
     * @throws InvalidArgumentException
     */
    private function secretKey(string $keyId): string
    {
        $file = (string) $this->option('secret-file');

        if ($file === '' || ! is_file($file)) {
            $this->reject('secret_file_missing');
        }

        $secret = base64_decode(trim((string) file_get_contents($file)), true);

        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            $this->reject('secret_invalid');
        }

        $keys = (array) config('pondok.theme_public_keys');
        $public = is_string($keys[$keyId] ?? null) ? trim($keys[$keyId]) : '';

        if ($public === '') {
            $this->reject('key_unknown');
        }

        if (! hash_equals($public, base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret)))) {
            $this->reject('key_mismatch', ['key' => $keyId]);
        }

        return $secret;
    }

    /**
     * @param  array<string, string>  $replace
     *
     * @throws InvalidArgumentException
     */
    private function reject(string $reason, array $replace = []): never
    {
        throw new InvalidArgumentException(__("pondok.theme_sign.{$reason}", $replace));
    }
}
