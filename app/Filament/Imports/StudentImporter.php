<?php

namespace App\Filament\Imports;

use App\Actions\Akademik\ImportStudentRow;
use App\Models\Student;
use App\Models\Unit;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Database\Eloquent\Model;

class StudentImporter extends CsvImporter
{
    protected static ?string $model = Student::class;

    /**
     * @return array<ImportColumn>
     */
    public static function getColumns(): array
    {
        return [
            static::column('nis', true, ['max:255'])->example('2026001'),
            static::column('name', true, ['max:255'])->example('Ahmad Fauzan'),
            static::column('gender', true, ['in:L,P'])->example('L')
                ->castStateUsing(fn (?string $state): ?string => filled($state) ? mb_strtoupper(trim($state)) : null),
            static::column('birth_place', true, ['max:255'])->example('Bandung'),
            static::column('birth_date', true, ['date_format:Y-m-d'])->example('2012-03-15'),
            static::column('unit', true)->example(Unit::query()->orderBy('sort_order')->value('code') ?? 'SLTP'),
            static::column('entry_year', true, ['regex:/^\d{4}$/'])->example('2026'),
            static::column('classroom')->example(''),
            ...static::guardianColumns(1, 'Abdullah Rahman', 'ayah', '080000000001', 'ya'),
            ...static::guardianColumns(2, 'Khadijah Aisyah', 'ibu', '080000000002', 'tidak'),
        ];
    }

    /**
     * @return list<ImportColumn>
     */
    private static function guardianColumns(int $number, string $name, string $relation, string $phone, string $billing): array
    {
        $lower = fn (?string $state): ?string => filled($state) ? mb_strtolower(trim($state)) : null;

        return [
            static::column("guardian{$number}_name", false, ['max:255'])->example($name),
            static::column("guardian{$number}_relation", false, ['in:ayah,ibu,wali'])->example($relation)->castStateUsing($lower),
            static::column("guardian{$number}_phone", false, ['max:50'])->example($phone),
            static::column("guardian{$number}_billing", false, ['in:ya,tidak'])->example($billing)->castStateUsing($lower),
        ];
    }

    protected static function columnLangPrefix(): string
    {
        return 'pondok.akademik.import.columns';
    }

    public function resolveRecord(): ?Model
    {
        return new Student;
    }

    public function saveRecord(): void
    {
        $this->record = app(ImportStudentRow::class)->handle($this->data);
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        return __('pondok.akademik.import.completed', [
            'success' => number_format($import->successful_rows),
            'failed' => number_format($import->getFailedRowsCount()),
        ]);
    }
}
