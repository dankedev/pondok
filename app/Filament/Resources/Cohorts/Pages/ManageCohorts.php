<?php

namespace App\Filament\Resources\Cohorts\Pages;

use App\Filament\Resources\Cohorts\CohortResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCohorts extends ManageRecords
{
    protected static string $resource = CohortResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
