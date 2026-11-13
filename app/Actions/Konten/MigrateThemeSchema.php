<?php

namespace App\Actions\Konten;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Menyamakan manifest tema ke skema yang dipahami inti (F102), dalam memori; berkas tema tidak diubah.
 *
 * Inti mendukung skema N (`CURRENT`) dan N-1. Skema baru dinaikkan hanya untuk perubahan yang merusak;
 * saat itu `CURRENT` naik dan langkah migrasi N-1 -> N ditambahkan di sini. Selama masih v1, hanya v1 yang ada.
 */
class MigrateThemeSchema
{
    public const CURRENT = 1;

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException bila `schema_version` bukan bilangan bulat atau di luar dukungan
     */
    public function handle(array $manifest): array
    {
        $version = $manifest['schema_version'] ?? null;

        if ($version !== self::CURRENT) {
            throw new InvalidArgumentException(__('pondok.theme_schema.unsupported_version', ['version' => Str::limit((string) json_encode($version), 80)]));
        }

        return $manifest;
    }
}
