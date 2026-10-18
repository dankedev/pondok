<?php

namespace App\Filament\Imports;

use App\Actions\Akademik\ImportStudentRow;
use App\Models\Student;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

class StudentImporter extends Importer
{
    protected static ?string $model = Student::class;

    protected static bool $shouldPreventFormulaInjection = true;

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
            static::column('unit', true)->example('SLTP'),
            static::column('entry_year', true, ['regex:/^\d{4}$/'])->example('2026'),
            static::column('classroom')->example(''),
            ...static::guardianColumns(1, 'Abdullah Rahman', 'ayah', '081234567890', 'ya'),
            ...static::guardianColumns(2, 'Khadijah Aisyah', 'ibu', '081200000000', 'tidak'),
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

    /**
     * @param  list<string>  $rules
     */
    private static function column(string $name, bool $required = false, array $rules = []): ImportColumn
    {
        $label = __("pondok.akademik.import.columns.{$name}");

        return ImportColumn::make($name)
            ->label($label)
            ->guess([$label, mb_strtolower($label)])
            ->requiredMapping($required)
            ->rules([$required ? 'required' : 'nullable', ...$rules])
            ->fillRecordUsing(fn () => null);
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

    /**
     * Antrean `sync` hanya mengirim notifikasi sesi (toast); simpan juga ke
     * database supaya hasil impor tetap ada di lonceng notifikasi.
     */
    public static function modifyCompletedNotification(Notification $notification, Import $import): Notification
    {
        if (config('queue.default') === 'sync') {
            $notification->sendToDatabase($import->user);
        }

        return $notification;
    }
}
