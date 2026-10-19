<?php

namespace App\Filament\Imports;

use App\Actions\Core\ImportStaffRow;
use App\Models\Staff;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Database\Eloquent\Model;

class StaffImporter extends CsvImporter
{
    protected static ?string $model = Staff::class;

    /**
     * @return array<ImportColumn>
     */
    public static function getColumns(): array
    {
        return [
            static::column('nip', false, ['max:50'])->example('197001012026001'),
            static::column('name', true, ['max:255'])->example('Ustadz Contoh'),
            static::column('gender', true, ['in:L,P'])->example('L')
                ->castStateUsing(fn (?string $state): ?string => filled($state) ? mb_strtoupper(trim($state)) : null),
            static::column('phone', false, ['max:30'])->example('0800000000001'),
            static::column('position', true, ['max:100'])->example('Ustadz'),
            static::column('is_active', false, ['in:ya,tidak'])->example('ya')
                ->castStateUsing(fn (?string $state): ?string => filled($state) ? mb_strtolower(trim($state)) : null),
        ];
    }

    protected static function columnLangPrefix(): string
    {
        return 'pondok.staff.import.columns';
    }

    public function resolveRecord(): ?Model
    {
        return new Staff;
    }

    public function saveRecord(): void
    {
        $this->record = app(ImportStaffRow::class)->handle($this->data);
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        return __('pondok.staff.import.completed', [
            'success' => number_format($import->successful_rows),
            'failed' => number_format($import->getFailedRowsCount()),
        ]);
    }
}
