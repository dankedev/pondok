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
            // Approver keringanan dirujuk FK restrict; user itu dinonaktifkan, bukan dihapus.
            DeleteAction::make()
                ->hidden(fn (): bool => $this->getRecord()->is(auth()->user())
                    || $this->getRecord()->approvedFeeOverrides()->exists()),
        ];
    }
}
