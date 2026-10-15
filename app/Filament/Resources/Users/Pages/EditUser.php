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
            // Approver keringanan, pembuat tagihan, serta penerima dan pembatal pembayaran
            // dirujuk FK restrict; user itu dinonaktifkan, bukan dihapus. Data keuangan
            // dicek tanpa cakupan jenjang.
            DeleteAction::make()
                ->hidden(fn (): bool => $this->getRecord()->is(auth()->user())
                    || $this->getRecord()->approvedFeeOverrides()->exists()
                    || $this->getRecord()->generatedInvoices()->withoutGlobalScopes()->exists()
                    || $this->getRecord()->receivedPayments()->withoutGlobalScopes()->exists()
                    || $this->getRecord()->cancelledPayments()->withoutGlobalScopes()->exists()),
        ];
    }
}
