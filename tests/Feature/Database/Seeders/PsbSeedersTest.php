<?php

use App\Models\AdmissionTrack;
use App\Models\DocumentType;
use App\Models\Scholarship;
use App\Models\Setting;
use Database\Seeders\AdmissionTrackSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\SettingSeeder;

it('AC-5: seeds the eight document types', function () {
    $this->seed(DocumentTypeSeeder::class);

    $types = DocumentType::query()->get()->keyBy('code');

    expect($types->keys()->sort()->values()->all())->toBe(['AKTA', 'BPJS', 'FOTO', 'FOTO_PRESTASI', 'IJAZAH', 'KK', 'RAPOR', 'SERTIFIKAT'])
        ->and($types['KK'])->name->toBe('Kartu Keluarga')->owner->toBe('santri')->is_required->toBeTrue()->is_confidential->toBeTrue()->has_expiry->toBeFalse()
        ->and($types['AKTA'])->name->toBe('Akta kelahiran')->owner->toBe('santri')->is_required->toBeTrue()->is_confidential->toBeTrue()->has_expiry->toBeFalse()
        ->and($types['FOTO'])->name->toBe('Pas foto')
        ->and($types['BPJS'])->owner->toBe('santri')->has_expiry->toBeTrue()->is_required->toBeFalse()->is_confidential->toBeFalse()
        ->and($types['FOTO_PRESTASI'])->name->toBe('Foto prestasi')->owner->toBe('prestasi')->is_required->toBeFalse()->is_confidential->toBeFalse()
        ->and($types['SERTIFIKAT'])->owner->toBe('prestasi')->is_required->toBeFalse()->is_confidential->toBeFalse()->has_expiry->toBeFalse();
});

it('AC-5: seeds plain student documents for the remaining types', function (string $code) {
    $this->seed(DocumentTypeSeeder::class);

    $type = DocumentType::query()->where('code', $code)->firstOrFail();

    expect($type)->owner->toBe('santri')->is_required->toBeFalse()->is_confidential->toBeFalse()->has_expiry->toBeFalse();
})->with(['IJAZAH', 'RAPOR', 'FOTO']);

it('AC-5: marks every seeded document type active', function () {
    $this->seed(DocumentTypeSeeder::class);

    expect(DocumentType::query()->where('is_active', false)->count())->toBe(0);
});

it('AC-5: does not duplicate or overwrite edited document types', function () {
    $this->seed(DocumentTypeSeeder::class);
    DocumentType::query()->where('code', 'KK')->update(['name' => 'KK (salinan)', 'is_active' => false]);

    $this->seed(DocumentTypeSeeder::class);

    expect(DocumentType::query()->count())->toBe(8)
        ->and(DocumentType::query()->where('code', 'KK')->first())->name->toBe('KK (salinan)')->is_active->toBeFalse();
});

it('AC-6: seeds the four default admission tracks', function () {
    $this->seed(AdmissionTrackSeeder::class);

    $tracks = AdmissionTrack::query()->get()->keyBy('code');

    expect($tracks->keys()->sort()->values()->all())->toBe(['PRESTASI', 'REGULER', 'TAHFIDZ', 'YATIM'])
        ->and($tracks->pluck('name', 'code')->all())->toBe(['PRESTASI' => 'Prestasi', 'REGULER' => 'Reguler', 'TAHFIDZ' => 'Tahfidz', 'YATIM' => 'Yatim'])
        ->and($tracks->every(fn (AdmissionTrack $track): bool => $track->is_active && $track->scholarship_id === null && $track->terms === null))->toBeTrue();
});

it('AC-6: does not duplicate or overwrite edited tracks', function () {
    $this->seed(AdmissionTrackSeeder::class);
    $scholarship = Scholarship::factory()->create();
    AdmissionTrack::query()->where('code', 'TAHFIDZ')->update(['scholarship_id' => $scholarship->id]);
    AdmissionTrack::query()->where('code', 'YATIM')->update(['is_active' => false]);

    $this->seed(AdmissionTrackSeeder::class);

    expect(AdmissionTrack::query()->count())->toBe(4)
        ->and(AdmissionTrack::query()->where('code', 'TAHFIDZ')->first()->scholarship_id)->toBe($scholarship->id)
        ->and(AdmissionTrack::query()->where('code', 'YATIM')->first()->is_active)->toBeFalse();
});

it('AC-6: seeds psb master data from DatabaseSeeder', function () {
    config(['pondok.super_admin.email' => 'admin@example.test', 'pondok.super_admin.password' => 'rahasia-123']);

    $this->seed(DatabaseSeeder::class);

    expect(DocumentType::query()->count())->toBe(8)
        ->and(AdmissionTrack::query()->count())->toBe(4);
});

it('AC-7: enables psb on a fresh install and leaves other modules as they were', function () {
    Setting::query()->delete();

    $this->seed(SettingSeeder::class);

    expect(Setting::moduleEnabled('psb'))->toBeTrue()
        ->and(Setting::moduleEnabled('akademik'))->toBeTrue()
        ->and(Setting::moduleEnabled('asrama'))->toBeTrue()
        ->and(Setting::moduleEnabled('keuangan'))->toBeTrue()
        ->and(Setting::moduleEnabled('konten'))->toBeTrue();
});

it('AC-7: does not overwrite a psb setting that was switched off', function () {
    $this->seed(SettingSeeder::class);
    Setting::set('modules.psb.enabled', false);

    $this->seed(SettingSeeder::class);

    expect(Setting::moduleEnabled('psb'))->toBeFalse();
});
