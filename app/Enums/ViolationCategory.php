<?php

namespace App\Enums;

/**
 * Kategori jenis pelanggaran santri (F026). Hanya pengelompokan; bobot ada di poin jenis.
 */
enum ViolationCategory: string
{
    case Light = 'ringan';
    case Moderate = 'sedang';
    case Severe = 'berat';

    public function label(): string
    {
        return __("pondok.kedisiplinan.categories.{$this->value}");
    }

    public function color(): string
    {
        return match ($this) {
            self::Light => 'info',
            self::Moderate => 'warning',
            self::Severe => 'danger',
        };
    }
}
