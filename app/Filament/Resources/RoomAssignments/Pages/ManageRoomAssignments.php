<?php

namespace App\Filament\Resources\RoomAssignments\Pages;

use App\Filament\Resources\RoomAssignments\RoomAssignmentResource;
use Filament\Resources\Pages\ManageRecords;

class ManageRoomAssignments extends ManageRecords
{
    protected static string $resource = RoomAssignmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RoomAssignmentResource::assignAction(),
        ];
    }
}
