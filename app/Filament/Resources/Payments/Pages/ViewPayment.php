<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Resources\Payments\PaymentResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewPayment extends ViewRecord
{
    protected static string $resource = PaymentResource::class;

    /**
     * Relasi yang dipakai infolist dimuat sekali, tanpa query per alokasi.
     */
    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load([
            'invoice.student.unit',
            'receiver',
            'canceller',
            'allocations.invoiceItem',
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            PaymentResource::printReceiptAction()->color('primary'),
            PaymentResource::downloadProofAction(),
            PaymentResource::cancelAction(),
        ];
    }
}
