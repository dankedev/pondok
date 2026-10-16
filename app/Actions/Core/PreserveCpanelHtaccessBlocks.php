<?php

namespace App\Actions\Core;

/**
 * Mempertahankan blok `.htaccess` yang ditulis cPanel (MultiPHP Manager dan
 * MultiPHP INI Editor). Tanpa blok handler, situs jatuh ke PHP bawaan server
 * dan error 500 setelah `.htaccess` ditimpa paket baru.
 *
 * Helper murni (extract/merge) ditambah simpan/pulihkan ke berkas di storage.
 */
class PreserveCpanelHtaccessBlocks
{
    public const STORAGE_PATH = 'framework/pondok-cpanel-htaccess';

    private const BEGIN = '/^#.*BEGIN cPanel-generated/';

    private const END = '/^#.*END cPanel-generated/';

    /**
     * Semua blok utuh dari baris BEGIN sampai baris END berikutnya. Blok tanpa
     * penutup diabaikan.
     *
     * @return list<string>
     */
    public function extract(string $htaccess): array
    {
        $blocks = [];
        $current = null;

        foreach (preg_split('/\r\n|\n|\r/', $htaccess) as $line) {
            if (preg_match(self::BEGIN, $line) === 1) {
                $current = [$line];

                continue;
            }

            if ($current === null) {
                continue;
            }

            $current[] = $line;

            if (preg_match(self::END, $line) === 1) {
                $blocks[] = implode("\n", $current);
                $current = null;
            }
        }

        return $blocks;
    }

    /**
     * Menambahkan blok yang belum ada di akhir isi baru. Blok dilewati bila isi
     * sudah memuatnya, atau sudah punya blok berjenis sama (baris BEGIN sama),
     * agar tidak muncul dua handler PHP yang bertentangan.
     *
     * @param  list<string>  $blocks
     */
    public function merge(string $newContent, array $blocks): string
    {
        return $this->mergeCounting($newContent, $blocks)[0];
    }

    /**
     * Menyimpan blok cPanel dari berkas ke storage. Simpanan lama tidak diubah
     * bila berkas tidak punya blok.
     */
    public function remember(string $htaccessPath): int
    {
        if (! is_file($htaccessPath)) {
            return 0;
        }

        $blocks = $this->extract((string) file_get_contents($htaccessPath));

        if ($blocks !== []) {
            file_put_contents(storage_path(self::STORAGE_PATH), implode("\n\n", $blocks)."\n");
        }

        return count($blocks);
    }

    /**
     * Menggabungkan blok tersimpan ke berkas bila belum ada. Mengembalikan
     * jumlah blok yang ditambahkan.
     */
    public function restore(string $htaccessPath): int
    {
        $stored = storage_path(self::STORAGE_PATH);

        if (! is_file($htaccessPath) || ! is_file($stored)) {
            return 0;
        }

        $content = (string) file_get_contents($htaccessPath);
        [$merged, $added] = $this->mergeCounting($content, $this->extract((string) file_get_contents($stored)));

        if ($added > 0) {
            file_put_contents($htaccessPath, $merged);
        }

        return $added;
    }

    /**
     * @param  list<string>  $blocks
     * @return array{0: string, 1: int}
     */
    private function mergeCounting(string $content, array $blocks): array
    {
        $added = 0;

        foreach ($blocks as $block) {
            $beginLines = array_map(fn (string $existing): string => strtok($existing, "\n"), $this->extract($content));

            if (str_contains($content, $block) || in_array(strtok($block, "\n"), $beginLines, true)) {
                continue;
            }

            $content = trim($content) === '' ? $block."\n" : rtrim($content, "\r\n")."\n\n".$block."\n";
            $added++;
        }

        return [$content, $added];
    }
}
