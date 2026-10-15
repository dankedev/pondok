<?php

namespace App\Filament\Resources\Applicants\Pages;

use App\Filament\Resources\Applicants\ApplicantResource;
use Filament\Resources\Pages\ListRecords;

class ListApplicants extends ListRecords
{
    protected static string $resource = ApplicantResource::class;
}
