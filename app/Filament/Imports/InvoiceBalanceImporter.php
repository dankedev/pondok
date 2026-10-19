<?php

namespace App\Filament\Imports;

use App\Actions\Keuangan\ImportOpeningBalanceRow;
use App\Models\Invoice;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Database\Eloquent\Model;

class InvoiceBalanceImporter extends CsvImporter
{
    protected static ?string $model = Invoice::class;

    /**
     * @return array<ImportColumn>
     */
    public static function getColumns(): array
    {
        return [
            static::column('nis', true, ['max:255'])->example('2026001'),
            static::column('fee_type', true, ['max:255'])->example('SPP'),
            static::column('amount', true, ['max:255'])->example('1500000'),
        ];
    }

    protected static function columnLangPrefix(): string
    {
        return 'pondok.invoice.import.columns';
    }

    public function resolveRecord(): ?Model
    {
        return new Invoice;
    }

    public function saveRecord(): void
    {
        $this->record = app(ImportOpeningBalanceRow::class)->handle($this->data);
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        return __('pondok.invoice.import.completed', [
            'success' => number_format($import->successful_rows),
            'failed' => number_format($import->getFailedRowsCount()),
        ]);
    }
}
