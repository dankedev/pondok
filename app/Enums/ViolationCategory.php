<?php

namespace App\Enums;

/**
 * Kategori jenis pelanggaran santri (F026). Hanya pengelompokan; bobot ada di poin jenis.
 */
enum ViolationCategory: string
{
    case Ringan = 'ringan';
    case Sedang = 'sedang';
    case Berat = 'berat';

    public function label(): string
    {
        return __("pondok.kedisiplinan.categories.{$this->value}");
    }

    public function color(): string
    {
        return match ($this) {
            self::Ringan => 'info',
            self::Sedang => 'warning',
            self::Berat => 'danger',
        };
    }
}
