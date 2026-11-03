<?php

namespace App\Actions\Konten;

use InvalidArgumentException;

/**
 * Pemeriksaan murah saat render (F105): aset tema ber-`assets` ada di `<folder tema>/assets/<hash12>` dan ukurannya cocok manifest
 * (sha256 penuh hanya diperiksa saat pasang/sign). Mengembalikan hash12 folder aset, atau null untuk tema tanpa `assets`.
 * Gagal = `InvalidArgumentException` berpesan Indonesia; pemanggil memperlakukan tema sebagai rusak (fallback ke chrome inti).
 */
class CheckThemeAssetsRuntime
{
    public function __construct(private readonly ValidateThemePackage $package) {}

    /**
     * @param  string  $path  path `theme.json`
     * @param  array<string, mixed>  $manifest  keluaran `ValidatePortalTheme`
     *
     * @throws InvalidArgumentException
     */
    public function handle(string $path, array $manifest): ?string
    {
        if (! isset($manifest['assets'])) {
            return null;
        }

        $hash = substr((string) hash_file('sha256', $path), 0, 12);

        $this->package->assertInstalled(dirname($path)."/assets/{$hash}", $manifest);

        return $hash;
    }
}
