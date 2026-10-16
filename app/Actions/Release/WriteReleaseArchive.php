<?php

namespace App\Actions\Release;

use RuntimeException;
use ZipArchive;

/**
 * Menulis paket rilis (zip berakar `pondok/`) beserta berkas checksum sha256.
 */
class WriteReleaseArchive
{
    /**
     * @param  list<string>  $manifest
     * @return array{path: string, sha256: string, files: int}
     */
    public function handle(string $root, array $manifest, string $zipPath, string $prefix = 'pondok/'): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException(__('pondok.release.zip_unavailable'));
        }

        $root = rtrim($root, '/');

        if (is_file($zipPath)) {
            unlink($zipPath);
        }

        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException(__('pondok.release.zip_failed', ['path' => $zipPath]));
        }

        foreach ($manifest as $path) {
            $zip->addFile($root.'/'.$path, $prefix.$path);

            // Pertahankan izin eksekusi (mis. `artisan`).
            if (is_executable($root.'/'.$path)) {
                $zip->setExternalAttributesName($prefix.$path, ZipArchive::OPSYS_UNIX, 0100755 << 16);
            }
        }

        if (! $zip->close()) {
            throw new RuntimeException(__('pondok.release.zip_failed', ['path' => $zipPath]));
        }

        $sha256 = (string) hash_file('sha256', $zipPath);
        file_put_contents($zipPath.'.sha256', $sha256.'  '.basename($zipPath)."\n");

        return ['path' => $zipPath, 'sha256' => $sha256, 'files' => count($manifest)];
    }
}
