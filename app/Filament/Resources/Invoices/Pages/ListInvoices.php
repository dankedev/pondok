<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Concerns\HasCsvImportActions;
use App\Filament\Imports\InvoiceBalanceImporter;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use Filament\Resources\Pages\ListRecords;

class ListInvoices extends ListRecords
{
    use HasCsvImportActions;

    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->csvImportActions(
                InvoiceBalanceImporter::class,
                __('pondok.invoice.import.template_file_name'),
                __('pondok.invoice.import.modal_description'),
                fn (): bool => auth()->user()?->can('import', Invoice::class) ?? false,
            ),
            InvoiceResource::generateAction(),
        ];
    }
}
