<?php

namespace App\Filament\Resources\DiniyahSubjects\Pages;

use App\Filament\Resources\DiniyahSubjects\DiniyahSubjectResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDiniyahSubjects extends ListRecords
{
    protected static string $resource = DiniyahSubjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('pondok.diniyah.subject.create_action')),
        ];
    }
}
