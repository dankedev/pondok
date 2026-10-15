<?php

namespace App\Filament\Resources\Dormitories\Pages;

use App\Filament\Resources\Dormitories\DormitoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDormitories extends ListRecords
{
    protected static string $resource = DormitoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
