<?php

namespace App\Filament\Pages;

use App\Enums\AttendanceStatus;
use App\Enums\Module;
use App\Filament\Resources\AttendanceSessions\AttendanceSessionResource;
use App\Filament\Resources\AttendanceSessions\Schemas\AttendanceSessionForm;
use App\Models\AcademicYear;
use App\Models\AttendanceSession;
use App\Models\Classroom;
use App\Models\Setting;
use App\Models\Student;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\Select;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Rekap absensi bulanan per rombel (F025): jumlah Hadir/Sakit/Izin/Alpa tiap santri yang punya baris
 * absensi di bulan itu. Query selalu lewat `AttendanceSession` ber-scope `visible`, jadi rombel di luar
 * cakupan user (kiriman langsung) menghasilkan tabel kosong. Bulan disaring dengan rentang `held_on`.
 *
 * @property-read Schema $form
 */
class AttendanceRecap extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'attendance-recap';

    protected string $view = 'filament.pages.attendance-recap';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function getModule(): string
    {
        return Module::Absensi->value;
    }

    public static function canAccess(): bool
    {
        return Setting::moduleEnabled(static::getModule()) && Gate::allows('viewAny', AttendanceSession::class);
    }

    /**
     * @return array<NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        return array_map(
            fn (NavigationItem $item): NavigationItem => $item->visible(fn (): bool => static::canAccess()),
            parent::getNavigationItems(),
        );
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.akademik');
    }

    public static function getNavigationLabel(): string
    {
        return __('pondok.attendance.recap.navigation');
    }

    public function getTitle(): string
    {
        return __('pondok.attendance.recap.title');
    }

    public function mount(): void
    {
        $this->form->fill([
            'classroom_id' => null,
            'month' => today()->format('Y-m'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    Select::make('classroom_id')
                        ->label(__('pondok.attendance.fields.classroom_id'))
                        ->placeholder(__('pondok.attendance.classroom_placeholder'))
                        ->options(fn (): array => AttendanceSessionForm::classroomLabels(
                            AttendanceSession::visibleClassrooms(AttendanceSessionResource::user())->orderByDesc('academic_year_id'),
                        ))
                        ->live(),
                    Select::make('month')
                        ->label(__('pondok.attendance.recap.month'))
                        ->options(fn (): array => $this->monthOptions())
                        ->selectablePlaceholder(false)
                        ->live(),
                ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('pondok.attendance.recap.students'))
            ->query(fn (): Builder => $this->studentsQuery())
            ->defaultSort('name')
            ->paginated(false)
            ->columns([
                TextColumn::make('nis')
                    ->label(__('pondok.student.nis')),
                TextColumn::make('name')
                    ->label(__('pondok.common.name')),
                ...array_map(fn (AttendanceStatus $status): TextColumn => TextColumn::make("{$status->value}_count")
                    ->label($status->label())
                    ->numeric()
                    ->alignEnd()
                    ->extraAttributes(['class' => 'tabular-nums']), AttendanceStatus::cases()),
            ])
            ->emptyStateIcon(Heroicon::OutlinedClipboardDocumentCheck)
            ->emptyStateHeading(fn (): string => $this->classroomId() === null
                ? __('pondok.attendance.recap.pick_classroom')
                : __('pondok.attendance.recap.empty'));
    }

    public function classroomId(): ?int
    {
        $value = $this->data['classroom_id'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Awal bulan terpilih (bawaan bulan ini bila nilai tidak sah).
     */
    public function monthStart(): CarbonImmutable
    {
        $value = (string) ($this->data['month'] ?? '');

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1
            ? CarbonImmutable::parse("{$value}-01", config('app.timezone'))
            : CarbonImmutable::today(config('app.timezone'))->startOfMonth();
    }

    /**
     * Santri (termasuk terarsip) yang punya baris absensi rombel terpilih di bulan itu, beserta jumlah per status.
     */
    private function studentsQuery(): Builder
    {
        $start = $this->monthStart();

        $sessions = AttendanceSession::query()
            ->where('classroom_id', $this->classroomId() ?? 0)
            ->where('held_on', '>=', $start->toDateString())
            ->where('held_on', '<', $start->addMonth()->toDateString())
            ->select('id');

        $inMonth = fn (Builder $query) => $query->whereIn('attendance_session_id', $sessions);

        return Student::withoutUnitScope()
            ->withTrashed()
            ->whereHas('attendanceRecords', $inMonth)
            ->withCount(collect(AttendanceStatus::cases())->mapWithKeys(fn (AttendanceStatus $status): array => [
                "attendanceRecords as {$status->value}_count" => fn (Builder $query) => $inMonth($query)->where('status', $status->value),
            ])->all());
    }

    /**
     * Bulan dalam tahun ajaran rombel terpilih (atau tahun ajaran aktif) sampai bulan ini, terbaru di atas.
     *
     * @return array<string, string>
     */
    private function monthOptions(): array
    {
        $classroomId = $this->classroomId();
        $classroom = $classroomId === null ? null : AttendanceSession::visibleClassrooms(AttendanceSessionResource::user())->find($classroomId);
        $year = $classroom instanceof Classroom ? $classroom->academicYear : AcademicYear::current();
        $today = CarbonImmutable::today(config('app.timezone'));

        $from = CarbonImmutable::parse($year?->starts_on?->toDateString() ?? $today, config('app.timezone'))->startOfMonth();
        $end = CarbonImmutable::parse($year?->ends_on?->toDateString() ?? $today, config('app.timezone'));
        $until = ($end->lt($today) ? $end : $today)->startOfMonth();

        $options = [];

        for ($month = $until; $month->gte($from); $month = $month->subMonth()) {
            $options[$month->format('Y-m')] = $month->translatedFormat('F Y');
        }

        return $options ?: [$today->format('Y-m') => $today->translatedFormat('F Y')];
    }
}
