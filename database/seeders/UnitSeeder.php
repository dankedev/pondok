<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;

class UnitSeeder extends Seeder
{
    public const UNITS = [
        ['code' => 'SD', 'name' => 'Sekolah Dasar', 'sort_order' => 1],
        ['code' => 'SLTP', 'name' => 'Sekolah Lanjutan Tingkat Pertama', 'sort_order' => 2],
        ['code' => 'SLTA', 'name' => 'Sekolah Lanjutan Tingkat Atas', 'sort_order' => 3],
    ];

    public function run(): void
    {
        foreach (self::UNITS as $unit) {
            Unit::query()->firstOrCreate(['code' => $unit['code']], $unit);
        }
    }
}
