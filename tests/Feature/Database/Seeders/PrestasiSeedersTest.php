<?php

use App\Models\DocumentType;
use App\Models\Setting;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\SettingSeeder;

it('AC-6: enables prestasi and seeds the achievement photo type', function () {
    Setting::query()->delete();

    $this->seed(SettingSeeder::class);
    $this->seed(DocumentTypeSeeder::class);

    $type = DocumentType::query()->where('code', 'FOTO_PRESTASI')->sole();

    expect(Setting::moduleEnabled('prestasi'))->toBeTrue()
        ->and($type->name)->toBe('Foto prestasi')
        ->and($type->owner)->toBe('prestasi')
        ->and($type->is_active)->toBeTrue()
        ->and($type->is_required)->toBeFalse()
        ->and($type->is_confidential)->toBeFalse();
});

it('AC-6: does not overwrite a prestasi switch that was turned off and does not duplicate the type', function () {
    $this->seed(SettingSeeder::class);
    $this->seed(DocumentTypeSeeder::class);
    Setting::set('modules.prestasi.enabled', false);

    $this->seed(SettingSeeder::class);
    $this->seed(DocumentTypeSeeder::class);

    expect(Setting::moduleEnabled('prestasi'))->toBeFalse()
        ->and(DocumentType::query()->where('code', 'FOTO_PRESTASI')->count())->toBe(1)
        ->and(DocumentType::query()->where('code', 'SERTIFIKAT')->count())->toBe(1);
});
