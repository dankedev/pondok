<?php

namespace App\Filament\Resources\AttendanceSessions\Schemas;

use App\Actions\Absensi\BuildAttendanceSheet;
use App\Enums\AttendanceStatus;
use App\Filament\Resources\AttendanceSessions\AttendanceSessionResource;
use App\Models\AcademicYear;
use App\Models\AttendanceSession;
use App\Models\Classroom;
use App\Models\LeaveRequest;
use App\Models\Student;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Form absensi harian (F025): pilih rombel + tanggal, lalu satu baris status per santri roster dari
 * `BuildAttendanceSheet` (`statuses.<student_id>`). Santri terkunci izin tampil `disabled`; santri
 * yang punya izin di tanggal itu diberi petunjuk jenis izin. Disimpan `RecordClassroomAttendance`.
 */
class AttendanceSessionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('pondok.attendance.section_session'))
                    ->columns(2)
                    ->schema([
                        Select::make('classroom_id')
                            ->label(__('pondok.attendance.fields.classroom_id'))
                            ->placeholder(__('pondok.attendance.classroom_placeholder'))
                            ->options(fn (?AttendanceSession $record): array => static::classroomOptions($record))
                            ->disabledOn('edit')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => static::loadStatuses($get, $set)),
                        DatePicker::make('held_on')
                            ->label(__('pondok.attendance.fields.held_on'))
                            ->default(fn (): string => today()->toDateString())
                            ->maxDate(fn (): string => today()->toDateString())
                            ->disabledOn('edit')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => static::loadStatuses($get, $set)),
                    ]),
                Section::make(__('pondok.attendance.section_sheet'))
                    ->description(__('pondok.attendance.sheet_description'))
                    ->schema(fn (Get $get): array => static::sheetFields($get('classroom_id'), $get('held_on'))),
            ]);
    }

    /**
     * Status awal tiap santri roster (tersimpan, `hadir`, atau `izin` bila terkunci).
     *
     * @return array<int, string>
     */
    public static function statuses(Collection $sheet): array
    {
        return $sheet
            ->mapWithKeys(fn (array $row): array => [$row['student']->id => $row['status']->value])
            ->all();
    }

    /**
     * Lembar rombel yang boleh dilihat user login pada tanggal itu, atau null bila belum lengkap/tidak sah.
     *
     * @return Collection<int, array{student: Student, status: AttendanceStatus, leave: ?LeaveRequest, locked: bool}>|null
     */
    public static function sheet(mixed $classroomId, mixed $date): ?Collection
    {
        if (! is_numeric($classroomId) || blank($date)) {
            return null;
        }

        $classroom = AttendanceSession::visibleClassrooms(AttendanceSessionResource::user())->find((int) $classroomId);

        try {
            $day = CarbonImmutable::parse((string) $date, config('app.timezone'))->startOfDay();
        } catch (Throwable) {
            return null;
        }

        return $classroom instanceof Classroom ? app(BuildAttendanceSheet::class)->handle($classroom, $day) : null;
    }

    private static function loadStatuses(Get $get, Set $set): void
    {
        $sheet = static::sheet($get('classroom_id'), $get('held_on'));

        $set('statuses', $sheet === null ? [] : static::statuses($sheet));
    }

    /**
     * @return array<int, string>
     */
    private static function classroomOptions(?AttendanceSession $record): array
    {
        return static::classroomLabels(AttendanceSession::visibleClassrooms(AttendanceSessionResource::user())
            ->where(fn (Builder $query) => $query
                ->where('academic_year_id', AcademicYear::current()?->id)
                ->when($record !== null, fn (Builder $query) => $query->orWhereKey($record->classroom_id))));
    }

    /**
     * Opsi rombel "7A · MTW/SMP" dari query rombel yang sudah dibatasi cakupannya.
     *
     * @param  Builder<Classroom>  $query
     * @return array<int, string>
     */
    public static function classroomLabels(Builder $query): array
    {
        return $query
            ->with('unit')
            ->orderBy('grade')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Classroom $classroom): array => [$classroom->id => "{$classroom->name} · {$classroom->unit?->name}"])
            ->all();
    }

    /**
     * @return array<int, mixed>
     */
    private static function sheetFields(mixed $classroomId, mixed $date): array
    {
        $sheet = static::sheet($classroomId, $date);

        if ($sheet === null || $sheet->isEmpty()) {
            return [
                EmptyState::make($sheet === null ? __('pondok.attendance.sheet_pick_first') : __('pondok.attendance.sheet_empty'))
                    ->icon(Heroicon::OutlinedUserGroup)
                    ->compact()
                    ->contained(false),
            ];
        }

        $options = collect(AttendanceStatus::cases())->mapWithKeys(fn (AttendanceStatus $status): array => [$status->value => $status->label()])->all();
        $colors = collect(AttendanceStatus::cases())->mapWithKeys(fn (AttendanceStatus $status): array => [$status->value => $status->color()])->all();

        return $sheet->map(fn (array $row): ToggleButtons => ToggleButtons::make("statuses.{$row['student']->id}")
            ->label($row['student']->name)
            ->options($options)
            ->colors($colors)
            ->grouped()
            ->inlineLabel()
            ->disabled($row['locked'])
            ->helperText(static::hint($row)))
            ->all();
    }

    /**
     * @param  array{locked: bool, leave: ?LeaveRequest}  $row
     */
    private static function hint(array $row): ?string
    {
        $type = $row['leave']?->leaveType?->name;

        if ($type === null) {
            return null;
        }

        return __($row['locked'] ? 'pondok.attendance.locked_hint' : 'pondok.attendance.leave_hint', ['type' => $type]);
    }
}
