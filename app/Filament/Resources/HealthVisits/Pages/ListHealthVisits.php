<?php

namespace App\Filament\Resources\HealthVisits\Pages;

use App\Filament\Resources\HealthVisits\HealthVisitResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListHealthVisits extends ListRecords
{
    protected static string $resource = HealthVisitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('pondok.health_visit.create_action')),
        ];
    }
}
