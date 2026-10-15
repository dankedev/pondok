<?php

namespace App\Filament\Resources\FeeOverrides\Pages;

use App\Filament\Resources\FeeOverrides\FeeOverrideResource;
use Filament\Resources\Pages\ManageRecords;

class ManageFeeOverrides extends ManageRecords
{
    protected static string $resource = FeeOverrideResource::class;

    protected function getHeaderActions(): array
    {
        return [
            FeeOverrideResource::createAction(),
        ];
    }
}
