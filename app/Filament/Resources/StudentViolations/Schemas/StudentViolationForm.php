<?php

namespace App\Filament\Resources\StudentViolations\Schemas;

use App\Filament\Resources\StudentViolations\StudentViolationResource;
use App\Models\Student;
use App\Models\StudentViolation;
use App\Models\ViolationType;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Form pelanggaran. Disimpan `RecordStudentViolation`; santri tidak bisa diganti saat edit
 * (salah santri: batalkan lalu catat ulang). Hanya jenis aktif yang bisa dipilih.
 */
class StudentViolationForm
{
    public const STUDENT_SEARCH_LIMIT = 50;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('pondok.kedisiplinan.violation.section_record'))
                    ->columns(2)
                    ->schema([
                        Select::make('student_id')
                            ->label(__('pondok.kedisiplinan.fields.student_id'))
                            ->placeholder(__('pondok.kedisiplinan.violation.student_placeholder'))
                            ->helperText(fn (string $operation): ?string => $operation === 'create' ? __('pondok.kedisiplinan.violation.student_helper') : null)
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => static::searchStudents($search))
                            ->getOptionLabelUsing(fn (mixed $value, ?StudentViolation $record): ?string => static::studentLabel($value, $record))
                            ->disabledOn('edit')
                            ->required(),
                        DatePicker::make('occurred_on')
                            ->label(__('pondok.kedisiplinan.fields.occurred_on'))
                            ->default(fn () => today())
                            ->maxDate(fn () => today())
                            ->required(),
                        Select::make('violation_type_id')
                            ->label(__('pondok.kedisiplinan.fields.violation_type_id'))
                            ->options(fn (?StudentViolation $record): array => static::typeOptions($record))
                            ->required()
                            ->columnSpanFull(),
                        Textarea::make('description')
                            ->label(__('pondok.kedisiplinan.fields.description'))
                            ->rows(3)
                            ->maxLength(StudentViolation::DESCRIPTION_MAX_LENGTH)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Jenis aktif, ditambah jenis catatan yang sedang diedit walau sudah nonaktif.
     *
     * @return array<int, string>
     */
    public static function typeOptions(?StudentViolation $record = null): array
    {
        return ViolationType::query()
            ->where(fn ($query) => $query->where('is_active', true)->when($record, fn ($query) => $query->orWhereKey($record->violation_type_id)))
            ->orderBy('points')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (ViolationType $type): array => [
                $type->id => "{$type->name} ({$type->category->label()}, {$type->points} ".__('pondok.kedisiplinan.violation_type.points_suffix').')',
            ])
            ->all();
    }

    /**
     * Santri aktif yang boleh dicatat user login (`Student::visibleStudentsFor`), dicari dari nama atau NIS.
     *
     * @return array<int, string>
     */
    public static function searchStudents(string $search): array
    {
        $like = '%'.trim($search).'%';

        return Student::visibleStudentsFor(StudentViolationResource::user())
            ->active()
            ->where(fn ($query) => $query->where('name', 'like', $like)->orWhere('nis', 'like', $like))
            ->orderBy('name')
            ->limit(self::STUDENT_SEARCH_LIMIT)
            ->get(['id', 'nis', 'name'])
            ->mapWithKeys(fn (Student $student): array => [$student->id => static::formatStudent($student)])
            ->all();
    }

    /**
     * Label santri terpilih: hanya santri yang terlihat user atau santri catatan yang sedang diedit,
     * agar `student_id` palsu di payload tidak membocorkan nama/NIS.
     */
    public static function studentLabel(mixed $value, ?StudentViolation $record = null): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        $student = (int) $value === $record?->student_id
            ? $record->student
            : Student::visibleStudentsFor(StudentViolationResource::user())->withTrashed()->find((int) $value);

        return $student === null ? null : static::formatStudent($student);
    }

    private static function formatStudent(Student $student): string
    {
        return "{$student->nis} — {$student->name}";
    }
}
