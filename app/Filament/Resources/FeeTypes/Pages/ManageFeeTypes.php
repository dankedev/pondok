<?php

namespace App\Filament\Resources\FeeTypes\Pages;

use App\Filament\Resources\FeeTypes\FeeTypeResource;
use Filament\Resources\Pages\ManageRecords;

class ManageFeeTypes extends ManageRecords
{
    protected static string $resource = FeeTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            FeeTypeResource::createAction(),
        ];
    }
}
