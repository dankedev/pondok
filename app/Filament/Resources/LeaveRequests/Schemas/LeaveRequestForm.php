<?php

namespace App\Filament\Resources\LeaveRequests\Schemas;

use App\Enums\LeaveRequestVia;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Student;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Form ajukan izin; disimpan `SubmitLeaveRequest`. Santri dari `Student::visibleStudentsFor` (musyrif: hanya
 * santri binaan), jenis izin hanya yang aktif. Izin yang sudah diajukan tidak diubah (batal lalu ajukan ulang).
 */
class LeaveRequestForm
{
    public const STUDENT_SEARCH_LIMIT = 50;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('pondok.leave_request.section_request'))
                    ->columns(2)
                    ->schema([
                        Select::make('student_id')
                            ->label(__('pondok.leave_request.fields.student_id'))
                            ->placeholder(__('pondok.leave_request.student_placeholder'))
                            ->helperText(__('pondok.leave_request.student_helper'))
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => static::searchStudents($search))
                            ->getOptionLabelUsing(fn (mixed $value): ?string => static::studentLabel($value))
                            ->required(),
                        Select::make('leave_type_id')
                            ->label(__('pondok.leave_request.fields.leave_type_id'))
                            ->options(fn (): array => LeaveType::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                            ->required(),
                        Radio::make('requested_via')
                            ->label(__('pondok.leave_request.fields.requested_via'))
                            ->helperText(__('pondok.leave_request.requested_via_helper'))
                            ->options(collect(LeaveRequestVia::cases())->mapWithKeys(fn (LeaveRequestVia $via): array => [$via->value => $via->label()])->all())
                            ->default(LeaveRequestVia::Musyrif->value)
                            ->inline()
                            ->required()
                            ->columnSpanFull(),
                        Textarea::make('reason')
                            ->label(__('pondok.leave_request.fields.reason'))
                            ->placeholder(__('pondok.leave_request.reason_placeholder'))
                            ->rows(3)
                            ->maxLength(LeaveRequest::TEXT_MAX_LENGTH)
                            ->required()
                            ->columnSpanFull(),
                    ]),
                Section::make(__('pondok.leave_request.section_schedule'))
                    ->columns(2)
                    ->schema([
                        DateTimePicker::make('planned_departure')
                            ->label(__('pondok.leave_request.fields.planned_departure'))
                            ->seconds(false)
                            ->default(fn () => now())
                            ->required(),
                        DateTimePicker::make('return_due')
                            ->label(__('pondok.leave_request.fields.return_due'))
                            ->helperText(__('pondok.leave_request.return_due_helper'))
                            ->seconds(false)
                            ->after('planned_departure')
                            ->required(),
                        TextInput::make('pickup_name')
                            ->label(__('pondok.leave_request.fields.pickup_name'))
                            ->helperText(__('pondok.leave_request.pickup_helper'))
                            ->maxLength(255),
                        TextInput::make('pickup_phone')
                            ->label(__('pondok.leave_request.fields.pickup_phone'))
                            ->tel()
                            ->maxLength(30),
                    ]),
            ]);
    }

    /**
     * Santri aktif yang boleh diajukan izinnya oleh user login, dicari dari nama atau NIS.
     *
     * @return array<int, string>
     */
    public static function searchStudents(string $search): array
    {
        $like = '%'.trim($search).'%';

        return Student::visibleStudentsFor(LeaveRequestResource::user())
            ->active()
            ->where(fn ($query) => $query->where('name', 'like', $like)->orWhere('nis', 'like', $like))
            ->orderBy('name')
            ->limit(self::STUDENT_SEARCH_LIMIT)
            ->get(['id', 'nis', 'name'])
            ->mapWithKeys(fn (Student $student): array => [$student->id => static::formatStudent($student)])
            ->all();
    }

    /**
     * Label santri terpilih, hanya untuk santri yang terlihat user (agar `student_id` palsu tidak membocorkan nama).
     */
    public static function studentLabel(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        $student = Student::visibleStudentsFor(LeaveRequestResource::user())->find((int) $value);

        return $student === null ? null : static::formatStudent($student);
    }

    private static function formatStudent(Student $student): string
    {
        return "{$student->nis} — {$student->name}";
    }
}
