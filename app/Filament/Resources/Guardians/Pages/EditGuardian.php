<?php

namespace App\Filament\Resources\Guardians\Pages;

use App\Filament\Resources\Guardians\GuardianResource;
use App\Models\Guardian;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditGuardian extends EditRecord
{
    protected static string $resource = GuardianResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuardianResource::createPortalAccountAction(),
            GuardianResource::resendPortalInvitationAction(),
            // Pemilik dokumen tidak dihapus (relasi polymorphic tanpa FK); dicek tanpa cakupan jenjang.
            DeleteAction::make()
                ->hidden(fn (Guardian $record): bool => $record->hasAnyDocuments()),
        ];
    }
}
