<?php

namespace App\Filament\Resources\Dormitories\Pages;

use App\Filament\Resources\Dormitories\DormitoryResource;
use App\Models\Dormitory;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDormitory extends EditRecord
{
    protected static string $resource = DormitoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->hidden(fn (Dormitory $record): bool => ! DormitoryResource::canBeDeleted($record)),
        ];
    }
}
