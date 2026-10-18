<?php

namespace App\Filament\Resources\Students\Pages;

use App\Filament\Imports\StudentImporter;
use App\Filament\Resources\Students\StudentResource;
use App\Models\Student;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;

class ListStudents extends ListRecords
{
    protected static string $resource = StudentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportAction::make()
                ->importer(StudentImporter::class)
                ->chunkSize(100)
                ->maxRows(2000)
                ->modalDescription(__('pondok.akademik.import.modal_description'))
                ->visible(fn (): bool => auth()->user()?->can('import', Student::class) ?? false),
            CreateAction::make(),
        ];
    }
}
