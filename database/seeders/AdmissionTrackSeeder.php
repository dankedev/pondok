<?php

namespace Database\Seeders;

use App\Models\AdmissionTrack;
use Illuminate\Database\Seeder;

class AdmissionTrackSeeder extends Seeder
{
    /** Jalur pendaftaran bawaan tanpa beasiswa, urut kode. */
    public const TRACKS = [
        ['code' => 'PRESTASI', 'name' => 'Prestasi'],
        ['code' => 'REGULER', 'name' => 'Reguler'],
        ['code' => 'TAHFIDZ', 'name' => 'Tahfidz'],
        ['code' => 'YATIM', 'name' => 'Yatim'],
    ];

    public function run(): void
    {
        // firstOrCreate agar seed ulang tidak menimpa beasiswa atau status yang sudah diatur.
        foreach (self::TRACKS as $track) {
            AdmissionTrack::query()->firstOrCreate(['code' => $track['code']], [...$track, 'is_active' => true]);
        }
    }
}
