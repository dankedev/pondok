<?php

namespace App\Filament\Resources\Students\Pages;

use App\Filament\Concerns\HasCsvImportActions;
use App\Filament\Imports\StudentImporter;
use App\Filament\Resources\Students\StudentResource;
use App\Models\Student;
use App\Models\Unit;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStudents extends ListRecords
{
    use HasCsvImportActions;

    protected static string $resource = StudentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->csvImportActions(
                StudentImporter::class,
                __('pondok.akademik.import.template_file_name'),
                fn (): string => __('pondok.akademik.import.modal_description', [
                    'units' => Unit::query()->orderBy('sort_order')->pluck('code')->implode(', '),
                ]),
                fn (): bool => auth()->user()?->can('import', Student::class) ?? false,
            ),
            CreateAction::make(),
        ];
    }
}
