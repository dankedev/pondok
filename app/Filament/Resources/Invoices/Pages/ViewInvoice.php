<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    /**
     * Relasi yang dipakai infolist dimuat sekali, tanpa query per item.
     */
    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load([
            'student.unit',
            'academicYear',
            'generator',
            'items.feeOverride',
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            InvoiceResource::recordPaymentAction(),
            InvoiceResource::cancelAction(),
        ];
    }
}
