<?php

namespace App\Filament\Resources\Halaqahs\Pages;

use App\Filament\Resources\Halaqahs\HalaqahResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListHalaqahs extends ListRecords
{
    protected static string $resource = HalaqahResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
