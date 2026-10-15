<?php

namespace App\Filament\Resources\Scholarships\Pages;

use App\Filament\Resources\Scholarships\ScholarshipResource;
use Filament\Resources\Pages\ManageRecords;

class ManageScholarships extends ManageRecords
{
    protected static string $resource = ScholarshipResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ScholarshipResource::createAction(),
        ];
    }
}
