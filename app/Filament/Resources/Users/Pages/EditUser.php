<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Approver keringanan dan pembuat tagihan dirujuk FK restrict; user itu
            // dinonaktifkan, bukan dihapus. Tagihan dicek tanpa cakupan jenjang.
            DeleteAction::make()
                ->hidden(fn (): bool => $this->getRecord()->is(auth()->user())
                    || $this->getRecord()->approvedFeeOverrides()->exists()
                    || $this->getRecord()->generatedInvoices()->withoutGlobalScopes()->exists()),
        ];
    }
}
