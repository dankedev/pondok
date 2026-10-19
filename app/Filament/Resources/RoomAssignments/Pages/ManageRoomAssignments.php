<?php

namespace App\Filament\Resources\RoomAssignments\Pages;

use App\Filament\Concerns\HasCsvImportActions;
use App\Filament\Imports\RoomAssignmentImporter;
use App\Filament\Resources\RoomAssignments\RoomAssignmentResource;
use App\Models\RoomAssignment;
use Filament\Resources\Pages\ManageRecords;

class ManageRoomAssignments extends ManageRecords
{
    use HasCsvImportActions;

    protected static string $resource = RoomAssignmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->csvImportActions(
                RoomAssignmentImporter::class,
                __('pondok.asrama.import.template_file_name'),
                __('pondok.asrama.import.modal_description'),
                fn (): bool => auth()->user()?->can('import', RoomAssignment::class) ?? false,
            ),
            RoomAssignmentResource::assignAction(),
        ];
    }
}
