<?php

namespace App\Console\Commands;

use App\Actions\Konten\ValidatePortalTheme;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use SodiumException;

/**
 * Untuk penerbit tema resmi (F103): menandatangani `<slug>/theme.json` dengan kunci rahasia Ed25519 dari berkas
 * dan mencetak satu objek JSON entri katalog. Kunci rahasia tidak pernah dibaca dari config, env, atau argumen.
 */
#[Signature('pondok:theme-sign {path : Path <slug>/theme.json} {--secret-file= : Berkas berisi kunci rahasia Ed25519 (base64, 64 byte)} {--key-id= : Id kunci publik di config (k1/k2)} {--url= : URL publik berkas tema}')]
#[Description('Menandatangani theme.json dan mencetak entri katalog tema resmi')]
class ThemeSignCommand extends Command
{
    public function handle(ValidatePortalTheme $validate): int
    {
        try {
            $path = (string) $this->argument('path');
            $bytes = is_file($path) ? (string) file_get_contents($path) : $this->reject('theme_missing');
            $manifest = $validate->handle($path);
            $keyId = (string) $this->option('key-id');
            $secret = $this->secretKey($keyId);
            $url = (string) $this->option('url');

            if ($url === '') {
                $this->reject('url_required');
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
