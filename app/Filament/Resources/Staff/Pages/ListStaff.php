<?php

namespace App\Filament\Resources\Staff\Pages;

use App\Filament\Concerns\HasCsvImportActions;
use App\Filament\Imports\StaffImporter;
use App\Filament\Resources\Staff\StaffResource;
use App\Models\Staff;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStaff extends ListRecords
{
    use HasCsvImportActions;

    protected static string $resource = StaffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->csvImportActions(
                StaffImporter::class,
                __('pondok.staff.import.template_file_name'),
                __('pondok.staff.import.modal_description'),
                fn (): bool => auth()->user()?->can('import', Staff::class) ?? false,
            ),
            CreateAction::make(),
        ];
    }
}
