<?php

namespace App\Filament\Resources\Students\Pages;

use App\Filament\Resources\Students\StudentResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditStudent extends EditRecord
{
    protected static string $resource = StudentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            StudentResource::changeUnitAction()
                ->after(fn () => $this->refreshFormData(['unit_id', 'cohort_id'])),
            DeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
