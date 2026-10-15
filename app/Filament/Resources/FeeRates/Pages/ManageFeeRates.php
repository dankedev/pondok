<?php

namespace App\Filament\Resources\FeeRates\Pages;

use App\Filament\Resources\FeeRates\FeeRateResource;
use Filament\Resources\Pages\ManageRecords;

class ManageFeeRates extends ManageRecords
{
    protected static string $resource = FeeRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            FeeRateResource::createAction(),
        ];
    }
}
