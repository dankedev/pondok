<?php

namespace App\Filament\Resources\AdmissionRequirements\Pages;

use App\Filament\Resources\AdmissionRequirements\AdmissionRequirementResource;
use Filament\Resources\Pages\ManageRecords;

class ManageAdmissionRequirements extends ManageRecords
{
    protected static string $resource = AdmissionRequirementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AdmissionRequirementResource::createAction(),
        ];
    }
}
