<?php

namespace App\Filament\Resources\AdmissionWaves\Pages;

use App\Filament\Resources\AdmissionWaves\AdmissionWaveResource;
use Filament\Resources\Pages\ManageRecords;

class ManageAdmissionWaves extends ManageRecords
{
    protected static string $resource = AdmissionWaveResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AdmissionWaveResource::createAction(),
        ];
    }
}
