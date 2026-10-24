<?php

namespace Database\Seeders;

use App\Enums\ViolationCategory;
use App\Models\DisciplineLevel;
use App\Models\ViolationType;
use Illuminate\Database\Seeder;

/**
 * Katalog jenis pelanggaran dan ambang poin bawaan saat instalasi (F026); bisa disesuaikan dari panel.
 * firstOrCreate agar seed ulang tidak menggandakan dan tidak menimpa poin atau label yang sudah diubah.
 */
class DisciplineSeeder extends Seeder
{
    /** Jenis bawaan: [kunci lang, kategori, poin]. */
    public const TYPES = [
        ['late_prayer', ViolationCategory::Ringan, 5],
        ['skip_study', ViolationCategory::Ringan, 5],
        ['improper_dress', ViolationCategory::Ringan, 5],
        ['late_return', ViolationCategory::Sedang, 15],
        ['phone', ViolationCategory::Sedang, 20],
        ['leave_without_permission', ViolationCategory::Sedang, 25],
        ['fighting', ViolationCategory::Berat, 50],
        ['stealing', ViolationCategory::Berat, 75],
    ];

    /** Ambang bawaan: poin minimal => nomor peringatan. */
    public const LEVELS = [25 => 1, 50 => 2, 75 => 3];

    public function run(): void
    {
        foreach (self::TYPES as [$key, $category, $points]) {
            ViolationType::query()->firstOrCreate(
                ['name' => __("pondok.kedisiplinan.defaults.types.{$key}")],
                ['category' => $category, 'points' => $points, 'is_active' => true],
            );
        }

        foreach (self::LEVELS as $minPoints => $number) {
            DisciplineLevel::query()->firstOrCreate(
                ['min_points' => $minPoints],
                ['name' => __('pondok.kedisiplinan.defaults.level', ['number' => $number])],
            );
        }
    }
}
