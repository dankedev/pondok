<?php

namespace App\Filament\Resources\HomecomingPeriods\Pages;

use App\Filament\Resources\HomecomingPeriods\HomecomingPeriodResource;
use Filament\Resources\Pages\ManageRecords;

class ManageHomecomingPeriods extends ManageRecords
{
    protected static string $resource = HomecomingPeriodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HomecomingPeriodResource::createAction(),
        ];
    }
}
