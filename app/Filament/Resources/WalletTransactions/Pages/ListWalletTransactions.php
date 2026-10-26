<?php

namespace App\Filament\Resources\WalletTransactions\Pages;

use App\Filament\Resources\WalletTransactions\WalletTransactionResource;
use Filament\Resources\Pages\ListRecords;

class ListWalletTransactions extends ListRecords
{
    protected static string $resource = WalletTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            WalletTransactionResource::recordAction(),
        ];
    }
}
