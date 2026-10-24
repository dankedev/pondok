<?php

namespace App\Filament\Resources\DisciplineLevels\Pages;

use App\Filament\Resources\DisciplineLevels\DisciplineLevelResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDisciplineLevels extends ListRecords
{
    protected static string $resource = DisciplineLevelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('pondok.kedisiplinan.level.create_action')),
        ];
    }
}
