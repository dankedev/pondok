<?php

namespace App\Filament\Pages;

use App\Actions\Akademik\SaveDiniyahGrades;
use App\Enums\Module;
use App\Filament\Concerns\ShowsActionErrorsOnPageForm;
use App\Filament\Resources\AttendanceSessions\Schemas\AttendanceSessionForm;
use App\Models\Classroom;
use App\Models\DiniyahGrade;
use App\Models\DiniyahReportNote;
use App\Models\DiniyahSubject;
use App\Models\Student;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Lembar nilai diniyah satu rombel pada satu semester (F027): kartu per santri berisi isian nilai tiap
 * mapel aktif jenjang rombel, catatan wali kelas, dan tautan cetak rapor. State `data.classroom_id`,
 * `data.semester`, `data.scores.<student_id>.<subject_id>`, `data.notes.<student_id>`. Simpan lewat
 * `SaveDiniyahGrades` (yang memeriksa akses rombel); pilihan rombel dibatasi `Classroom::visibleTo`.
 *
 * @property-read Schema $form
 */
class DiniyahGradeEntry extends Page
{
    use ShowsActionErrorsOnPageForm;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static ?int $navigationSort = 8;

    protected static ?string $slug = 'diniyah-grade-entry';

    protected string $view = 'filament.pages.diniyah-grade-entry';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function getModule(): string
    {
        return Module::Akademik->value;
    }

    public static function canAccess(): bool
    {
        return Module::Akademik->isEnabled() && (bool) auth()->user()?->can('input-diniyah-grades');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.akademik');
    }

    public static function getNavigationLabel(): string
    {
        return __('pondok.diniyah.entry.navigation');
    }

    public function getTitle(): string
    {
        return __('pondok.diniyah.entry.title');
    }

    public function mount(): void
    {
        $this->form->fill(['classroom_id' => null, 'semester' => 1, 'scores' => [], 'notes' => []]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label(__('pondok.diniyah.entry.save'))
                            ->submit('save')
                            ->keyBindings(['mod+s'])
                            ->visible(fn (): bool => $this->classroom() instanceof Classroom),
                    ]),
                ]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(1)
            ->components([
                Section::make(__('pondok.diniyah.entry.section_pick'))
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        Select::make('classroom_id')
                            ->label(__('pondok.attendance.fields.classroom_id'))
                            ->placeholder(__('pondok.attendance.classroom_placeholder'))
                            ->options(fn (): array => AttendanceSessionForm::classroomLabels(Classroom::visibleTo(static::user())->orderByDesc('academic_year_id')))
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $this->loadSheet($set)),
                        Select::make('semester')
                            ->label(__('pondok.diniyah.entry.semester'))
                            ->options([1 => __('pondok.diniyah.entry.semester_n', ['n' => 1]), 2 => __('pondok.diniyah.entry.semester_n', ['n' => 2])])
                            ->selectablePlaceholder(false)
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $this->loadSheet($set)),
                    ]),
                Grid::make(1)->schema(fn (Get $get): array => $this->sheetFields($get('classroom_id'), $get('semester'))),
            ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();
        $classroom = $this->classroom();

        if (! $classroom instanceof Classroom) {
            Notification::make()->title(__('pondok.diniyah.errors.forbidden'))->danger()->send();

            return;
        }

        $this->runShowingPageFormErrors(fn (): int => app(SaveDiniyahGrades::class)->handle(
            static::user(),
            $classroom,
            $data['semester'] ?? null,
            $data['scores'] ?? [],
            $data['notes'] ?? [],
        ));

        Notification::make()
            ->title(__('pondok.diniyah.entry.saved', ['classroom' => $classroom->name, 'semester' => $this->semester()]))
            ->success()
            ->send();
    }

    /**
     * Rombel terpilih bila dalam cakupan user login, selain itu null.
     */
    private function classroom(): ?Classroom
    {
        $id = $this->data['classroom_id'] ?? null;

        return is_numeric($id) ? Classroom::visibleTo(static::user())->find((int) $id) : null;
    }

    private function semester(): int
    {
        return (int) ($this->data['semester'] ?? 1) === 2 ? 2 : 1;
    }

    /**
     * Mapel aktif jenjang rombel, urut `sort_order`.
     *
     * @return Collection<int, DiniyahSubject>
     */
    private function subjects(Classroom $classroom): Collection
    {
        return DiniyahSubject::withoutUnitScope()
            ->active()
            ->where('unit_id', $classroom->unit_id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Isi nilai dan catatan tersimpan rombel terpilih ke state (sel kosong = null).
     */
    private function loadSheet(Set $set): void
    {
        $classroom = $this->classroom();

        if (! $classroom instanceof Classroom) {
            $set('scores', []);
            $set('notes', []);

            return;
        }

        $students = $classroom->rosterStudents()->pluck('id');
        $subjects = $this->subjects($classroom);
        $key = ['academic_year_id' => $classroom->academic_year_id, 'semester' => $this->semester()];

        $stored = DiniyahGrade::query()->where($key)->whereIn('student_id', $students)->get()
            ->mapWithKeys(fn (DiniyahGrade $grade): array => ["{$grade->student_id}.{$grade->diniyah_subject_id}" => $grade->score]);
        $notes = DiniyahReportNote::query()->where($key)->whereIn('student_id', $students)->pluck('note', 'student_id');

        $set('scores', $students->mapWithKeys(fn (int $studentId): array => [
            $studentId => $subjects->mapWithKeys(fn (DiniyahSubject $subject): array => [$subject->id => $stored->get("{$studentId}.{$subject->id}")])->all(),
        ])->all());
        $set('notes', $students->mapWithKeys(fn (int $studentId): array => [$studentId => $notes->get($studentId)])->all());
    }

    /**
     * @return array<int, mixed>
     */
    private function sheetFields(mixed $classroomId, mixed $semester): array
    {
        $classroom = is_numeric($classroomId) ? Classroom::visibleTo(static::user())->find((int) $classroomId) : null;

        if (! $classroom instanceof Classroom) {
            return [$this->emptyState(__('pondok.diniyah.entry.pick_first'))];
        }

        $subjects = $this->subjects($classroom);
        $students = $classroom->rosterStudents()->get();

        if ($subjects->isEmpty()) {
            return [$this->emptyState(__('pondok.diniyah.entry.no_subjects'))];
        }

        if ($students->isEmpty()) {
            return [$this->emptyState(__('pondok.diniyah.entry.no_students'))];
        }

        return $students->map(fn (Student $student): Section => Section::make($student->name)
            ->description(__('pondok.portal_wali.nis', ['nis' => $student->nis]))
            ->headerActions([
                Action::make("print_{$student->id}")
                    ->label(__('pondok.diniyah.entry.print'))
                    ->icon(Heroicon::OutlinedPrinter)
                    ->color('gray')
                    ->size('sm')
                    ->url(route('filament.admin.akademik.diniyah-report', [
                        'student' => $student->id,
                        'year' => $classroom->academic_year_id,
                        'semester' => (int) $semester === 2 ? 2 : 1,
                    ]), shouldOpenInNewTab: true),
            ])
            ->schema([
                Grid::make(['default' => 2, 'sm' => 3, 'lg' => 4])->schema(
                    $subjects->map(fn (DiniyahSubject $subject): TextInput => TextInput::make("scores.{$student->id}.{$subject->id}")
                        ->label($subject->name)
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->maxValue(100)
                        ->extraInputAttributes(['class' => 'tabular-nums', 'inputmode' => 'numeric']))->all(),
                ),
                Textarea::make("notes.{$student->id}")
                    ->label(__('pondok.diniyah.entry.note'))
                    ->rows(2)
                    ->maxLength(SaveDiniyahGrades::MAX_NOTE_LENGTH),
            ]))->all();
    }

    private function emptyState(string $heading): EmptyState
    {
        return EmptyState::make($heading)
            ->icon(Heroicon::OutlinedClipboardDocumentList)
            ->compact()
            ->contained(false);
    }

    private static function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
