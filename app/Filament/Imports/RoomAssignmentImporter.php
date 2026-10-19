<?php

namespace App\Filament\Imports;

use App\Actions\Asrama\ImportRoomAssignmentRow;
use App\Models\RoomAssignment;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Database\Eloquent\Model;

class RoomAssignmentImporter extends CsvImporter
{
    protected static ?string $model = RoomAssignment::class;

    /**
     * @return array<ImportColumn>
     */
    public static function getColumns(): array
    {
        return [
            static::column('nis', true, ['max:255'])->example('2026001'),
            static::column('dormitory', true, ['max:255'])->example('Asrama Abu Bakar'),
            static::column('room', true, ['max:255'])->example('Kamar 101'),
            static::column('start_on', false, ['date_format:Y-m-d'])->example('2026-07-15'),
        ];
    }

    protected static function columnLangPrefix(): string
    {
        return 'pondok.asrama.import.columns';
    }

    public function resolveRecord(): ?Model
    {
        return new RoomAssignment;
    }

    public function saveRecord(): void
    {
        $this->record = app(ImportRoomAssignmentRow::class)->handle($this->data);
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        return __('pondok.asrama.import.completed', [
            'success' => number_format($import->successful_rows),
            'failed' => number_format($import->getFailedRowsCount()),
        ]);
    }
}
