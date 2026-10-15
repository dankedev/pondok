<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use Illuminate\Database\Seeder;

class DocumentTypeSeeder extends Seeder
{
    /** Jenis dokumen bawaan; bisa disesuaikan dari panel (F013). */
    public const TYPES = [
        ['code' => 'KK', 'name' => 'Kartu Keluarga', 'owner' => DocumentType::OWNER_STUDENT, 'is_required' => true, 'is_confidential' => true],
        ['code' => 'AKTA', 'name' => 'Akta kelahiran', 'owner' => DocumentType::OWNER_STUDENT, 'is_required' => true, 'is_confidential' => true],
        ['code' => 'IJAZAH', 'name' => 'Ijazah', 'owner' => DocumentType::OWNER_STUDENT],
        ['code' => 'RAPOR', 'name' => 'Rapor', 'owner' => DocumentType::OWNER_STUDENT],
        ['code' => 'FOTO', 'name' => 'Pas foto', 'owner' => DocumentType::OWNER_STUDENT],
        ['code' => 'BPJS', 'name' => 'Kartu BPJS', 'owner' => DocumentType::OWNER_STUDENT, 'has_expiry' => true],
        ['code' => 'SERTIFIKAT', 'name' => 'Sertifikat prestasi', 'owner' => DocumentType::OWNER_ACHIEVEMENT],
    ];

    public function run(): void
    {
        // firstOrCreate agar seed ulang tidak menimpa jenis dokumen yang sudah diubah.
        foreach (self::TYPES as $type) {
            DocumentType::query()->firstOrCreate(['code' => $type['code']], [
                'is_required' => false,
                'has_expiry' => false,
                'is_confidential' => false,
                'is_active' => true,
                ...$type,
            ]);
        }
    }
}
