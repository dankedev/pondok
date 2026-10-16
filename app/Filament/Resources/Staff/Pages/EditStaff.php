<?php

namespace App\Filament\Resources\Staff\Pages;

use App\Filament\Resources\Staff\StaffResource;
use App\Models\Staff;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditStaff extends EditRecord
{
    protected static string $resource = StaffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Pemilik dokumen tidak dihapus (relasi polymorphic tanpa FK) dan penandatangan surat keluar
            // dirujuk FK restrict; keduanya dicek tanpa cakupan jenjang.
            DeleteAction::make()
                ->hidden(fn (Staff $record): bool => $record->hasAnyDocuments()
                    || $record->signedLetters()->withoutGlobalScopes()->exists()),
        ];
    }
}
