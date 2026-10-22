<?php

namespace App\Filament\Resources\HealthVisits\Schemas;

use App\Filament\Resources\HealthVisits\HealthVisitResource;
use App\Models\HealthVisit;
use App\Models\Student;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Form kunjungan kesehatan. Disimpan `RecordHealthVisit`/`UpdateHealthVisit`; santri tidak bisa
 * diganti saat edit (salah santri: hapus lalu catat ulang).
 */
class HealthVisitForm
{
    public const STUDENT_SEARCH_LIMIT = 50;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('pondok.health_visit.section_visit'))
                    ->columns(2)
                    ->schema([
                        Select::make('student_id')
                            ->label(__('pondok.health_visit.fields.student_id'))
                            ->placeholder(__('pondok.health_visit.student_placeholder'))
                            ->helperText(fn (string $operation): ?string => $operation === 'create' ? __('pondok.health_visit.student_helper') : null)
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => static::searchStudents($search))
                            ->getOptionLabelUsing(fn (mixed $value, ?HealthVisit $record): ?string => static::studentLabel($value, $record))
                            ->disabledOn('edit')
                            ->required(),
                        DateTimePicker::make('visited_at')
                            ->label(__('pondok.health_visit.fields.visited_at'))
                            ->seconds(false)
                            ->default(fn () => now())
                            ->maxDate(fn () => now())
                            ->required(),
                        Textarea::make('complaint')
                            ->label(__('pondok.health_visit.fields.complaint'))
                            ->placeholder(__('pondok.health_visit.complaint_placeholder'))
                            ->rows(3)
                            ->maxLength(HealthVisit::TEXT_MAX_LENGTH)
                            ->required()
                            ->columnSpanFull(),
                        Textarea::make('treatment')
                            ->label(__('pondok.health_visit.fields.treatment'))
                            ->placeholder(__('pondok.health_visit.treatment_placeholder'))
                            ->rows(3)
                            ->maxLength(HealthVisit::TEXT_MAX_LENGTH)
                            ->columnSpanFull(),
                    ]),
                Section::make(__('pondok.health_visit.section_follow_up'))
                    ->columns(2)
                    ->schema([
                        Toggle::make('needs_referral')
                            ->label(__('pondok.health_visit.fields.needs_referral'))
                            ->default(false),
                        Toggle::make('needs_leave')
                            ->label(__('pondok.health_visit.fields.needs_leave'))
                            ->default(false),
                        Textarea::make('note')
                            ->label(__('pondok.health_visit.fields.note'))
                            ->rows(2)
                            ->maxLength(HealthVisit::TEXT_MAX_LENGTH)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Santri aktif yang boleh dicatat user login (`HealthVisit::visibleStudents`), dicari dari nama atau NIS.
     *
     * @return array<int, string>
     */
    public static function searchStudents(string $search): array
    {
        $like = '%'.trim($search).'%';

        return HealthVisit::visibleStudents(HealthVisitResource::user())
            ->active()
            ->where(fn ($query) => $query->where('name', 'like', $like)->orWhere('nis', 'like', $like))
            ->orderBy('name')
            ->limit(self::STUDENT_SEARCH_LIMIT)
            ->get(['id', 'nis', 'name'])
            ->mapWithKeys(fn (Student $student): array => [$student->id => static::formatStudent($student)])
            ->all();
    }

    /**
     * Label santri terpilih: hanya santri yang terlihat user (termasuk terarsip) atau santri catatan
     * yang sedang diedit, agar `student_id` palsu di payload tidak membocorkan nama/NIS.
     */
    public static function studentLabel(mixed $value, ?HealthVisit $record = null): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        $student = (int) $value === $record?->student_id
            ? $record->student
            : HealthVisit::visibleStudents(HealthVisitResource::user())->withTrashed()->find((int) $value);

        return $student === null ? null : static::formatStudent($student);
    }

    private static function formatStudent(Student $student): string
    {
        return "{$student->nis} — {$student->name}";
    }
}
