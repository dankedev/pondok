<?php

namespace App\Actions\Konten;

use InvalidArgumentException;

/**
 * Daftar tema portal (F102): satu entri per subfolder `config('pondok.themes_path')` (bawaan `resources/themes`).
 *
 * Tema yang gagal validasi tetap muncul dengan `valid = false` dan alasannya, supaya bisa ditampilkan
 * nonaktif di Situs > Tampilan. Satu daftar dengan satu bentuk entri: sumber tema lain (F103) cukup
 * menambah folder atau menyambung di sini tanpa mengubah pemakainya. `default` selalu di urutan pertama.
 */
class ListPortalThemes
{
    public function __construct(private readonly ValidatePortalTheme $validate) {}

    /**
     * @return list<array{slug: string, name: string, description: string, valid: bool, error: ?string}>
     */
    public function handle(): array
    {
        $entries = [];

        foreach (glob(rtrim((string) config('pondok.themes_path'), '/').'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $slug = basename($directory);

            try {
                $manifest = $this->validate->handle("{$directory}/theme.json");

                $entries[] = ['slug' => $slug, 'name' => $manifest['name'], 'description' => $manifest['description'], 'valid' => true, 'error' => null];
            } catch (InvalidArgumentException $e) {
                $entries[] = ['slug' => $slug, 'name' => $slug, 'description' => '', 'valid' => false, 'error' => $e->getMessage()];
            }
        }

        usort($entries, fn (array $a, array $b): int => [$a['slug'] !== 'default', $a['slug']] <=> [$b['slug'] !== 'default', $b['slug']]);

        return $entries;
    }
}
