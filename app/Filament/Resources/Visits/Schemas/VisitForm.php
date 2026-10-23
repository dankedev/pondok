<?php

namespace App\Filament\Resources\Visits\Schemas;

use App\Models\Guardian;
use App\Models\Student;
use App\Models\Visit;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * Form kunjungan wali; disimpan `RecordVisit`/`UpdateVisit`. Santri tidak bisa diganti saat ubah, dan pilihan
 * wali hanya wali santri terpilih (Action tetap memeriksa ulang).
 */
class VisitForm
{
    public const STUDENT_SEARCH_LIMIT = 50;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('pondok.visit.section_visitor'))
                    ->columns(2)
                    ->schema([
                        Select::make('student_id')
                            ->label(__('pondok.visit.fields.student_id'))
                            ->placeholder(__('pondok.visit.student_placeholder'))
                            ->helperText(fn (string $operation): ?string => $operation === 'create' ? __('pondok.visit.student_helper') : null)
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => static::searchStudents($search))
                            ->getOptionLabelUsing(fn (mixed $value, ?Visit $record): ?string => static::studentLabel($value, $record))
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('guardian_id', null))
                            ->disabledOn('edit')
                            ->required(),
                        Select::make('guardian_id')
                            ->label(__('pondok.visit.fields.guardian_id'))
                            ->helperText(__('pondok.visit.guardian_helper'))
                            ->options(fn (Get $get): array => static::guardianOptions($get('student_id')))
                            ->live()
                            ->afterStateUpdated(fn (mixed $state, Get $get, Set $set) => static::fillVisitor($state, $get, $set)),
                        TextInput::make('visitor_name')
                            ->label(__('pondok.visit.fields.visitor_name'))
                            ->maxLength(255)
                            ->required(),
                        TextInput::make('relation')
                            ->label(__('pondok.visit.fields.relation'))
                            ->placeholder(__('pondok.visit.relation_placeholder'))
                            ->maxLength(50)
                            ->required(),
                    ]),
                Section::make(__('pondok.visit.section_time'))
                    ->columns(2)
                    ->schema([
                        DateTimePicker::make('started_at')
                            ->label(__('pondok.visit.fields.started_at'))
                            ->seconds(false)
                            ->default(fn () => now())
                            ->maxDate(fn () => now())
                            ->required(),
                        DateTimePicker::make('ended_at')
                            ->label(__('pondok.visit.fields.ended_at'))
                            ->seconds(false)
                            ->afterOrEqual('started_at'),
                        Textarea::make('items_note')
                            ->label(__('pondok.visit.fields.items_note'))
                            ->placeholder(__('pondok.visit.items_placeholder'))
                            ->rows(2)
                            ->maxLength(Visit::TEXT_MAX_LENGTH)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Santri aktif dalam cakupan jenjang user login, dicari dari nama atau NIS.
     *
     * @return array<int, string>
     */
    public static function searchStudents(string $search): array
    {
        $like = '%'.trim($search).'%';

        return Student::query()
            ->active()
            ->where(fn ($query) => $query->where('name', 'like', $like)->orWhere('nis', 'like', $like))
            ->orderBy('name')
            ->limit(self::STUDENT_SEARCH_LIMIT)
            ->get(['id', 'nis', 'name'])
            ->mapWithKeys(fn (Student $student): array => [$student->id => static::formatStudent($student)])
            ->all();
    }

    public static function studentLabel(mixed $value, ?Visit $record = null): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        $student = (int) $value === $record?->student_id
            ? $record->student
            : Student::query()->find((int) $value);

        return $student === null ? null : static::formatStudent($student);
    }

    /**
     * Wali santri terpilih (dalam cakupan jenjang user).
     *
     * @return array<int, string>
     */
    public static function guardianOptions(mixed $studentId): array
    {
        if (! is_numeric($studentId)) {
            return [];
        }

        return Student::query()->withTrashed()->find((int) $studentId)?->guardians()
            ->orderBy('name')
            ->pluck('guardians.name', 'guardians.id')
            ->all() ?? [];
    }

    /**
     * Isi nama pengunjung dan hubungan dari wali yang dipilih bila masih kosong.
     */
    protected static function fillVisitor(mixed $guardianId, Get $get, Set $set): void
    {
        if (! is_numeric($guardianId) || ! is_numeric($get('student_id'))) {
            return;
        }

        $guardian = Student::query()->find((int) $get('student_id'))?->guardians()->find((int) $guardianId);

        if (! $guardian instanceof Guardian) {
            return;
        }

        if (blank($get('visitor_name'))) {
            $set('visitor_name', $guardian->name);
        }

        if (blank($get('relation')) && filled($guardian->pivot->relation ?? null)) {
            $set('relation', $guardian->pivot->relation);
        }
    }

    private static function formatStudent(Student $student): string
    {
        return "{$student->nis} — {$student->name}";
    }
}
