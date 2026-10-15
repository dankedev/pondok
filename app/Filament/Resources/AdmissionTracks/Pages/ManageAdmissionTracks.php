<?php

namespace App\Filament\Resources\AdmissionTracks\Pages;

use App\Filament\Resources\AdmissionTracks\AdmissionTrackResource;
use Filament\Resources\Pages\ManageRecords;

class ManageAdmissionTracks extends ManageRecords
{
    protected static string $resource = AdmissionTrackResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AdmissionTrackResource::createAction(),
        ];
    }
}
