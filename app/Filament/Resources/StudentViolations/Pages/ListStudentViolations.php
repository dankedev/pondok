<?php

namespace App\Filament\Resources\StudentViolations\Pages;

use App\Filament\Resources\StudentViolations\StudentViolationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStudentViolations extends ListRecords
{
    protected static string $resource = StudentViolationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('pondok.kedisiplinan.violation.create_action')),
        ];
    }
}
