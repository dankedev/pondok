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
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Input nilai diniyah satu rombel pada satu semester (F027, F096), dua mode:
 * - `classroom` (bawaan): satu mapel untuk semua santri roster; "Simpan semua" (`save`) dan "Simpan" per baris
 *   (`saveRow`). Tanpa submit Enter dan tanpa catatan wali kelas.
 * - `student`: semua mapel aktif satu santri + catatan wali kelas; "Simpan nilai" (`save`, submit form).
 * State `data.mode`, `data.classroom_id`, `data.semester`, `data.subject_id`, `data.student_id`,
 * `data.scores.<student_id>.<subject_id>`, `data.notes.<student_id>`. State klien disaring ke subset mode
 * sebelum diteruskan ke `SaveDiniyahGrades` (yang memeriksa akses rombel, roster, dan mapel). Penanda KKM
 * dihitung dari nilai tersimpan. Pilihan rombel dibatasi `Classroom::visibleTo`.
 *
 * @property-read Schema $form
 */
class DiniyahGradeEntry extends Page
{
    use ShowsActionErrorsOnPageForm;

    public const MODE_CLASSROOM = 'classroom';

    public const MODE_STUDENT = 'student';

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
        $this->form->fill([
            'mode' => self::MODE_CLASSROOM,
            'classroom_id' => null,
            'semester' => 1,
            'subject_id' => null,
            'student_id' => null,
            'scores' => [],
            'notes' => [],
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler(fn (): ?string => $this->mode() === self::MODE_STUDENT ? 'save' : null)
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label(fn (): string => $this->mode() === self::MODE_STUDENT ? __('pondok.diniyah.entry.save') : __('pondok.diniyah.entry.save_all'))
                            ->action('save')
                            ->keyBindings(['mod+s'])
                            ->visible(fn (): bool => $this->sheetReady()),
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
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        ToggleButtons::make('mode')
                            ->label(__('pondok.diniyah.entry.mode'))
                            ->options([
                                self::MODE_CLASSROOM => __('pondok.diniyah.entry.mode_classroom'),
                                self::MODE_STUDENT => __('pondok.diniyah.entry.mode_student'),
                            ])
                            ->icons([
                                self::MODE_CLASSROOM => Heroicon::OutlinedUserGroup,
                                self::MODE_STUDENT => Heroicon::OutlinedUser,
                            ])
                            ->grouped()
                            ->required()
                            ->columnSpanFull()
                            ->live()
                            ->afterStateUpdated(fn () => $this->resetTarget()),
                        Select::make('classroom_id')
                            ->label(__('pondok.attendance.fields.classroom_id'))
                            ->placeholder(__('pondok.attendance.classroom_placeholder'))
                            ->options(fn (): array => AttendanceSessionForm::classroomLabels(Classroom::visibleTo(static::user())->orderByDesc('academic_year_id')))
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn () => $this->resetTarget()),
                        Select::make('semester')
                            ->label(__('pondok.diniyah.entry.semester'))
                            ->options([1 => __('pondok.diniyah.entry.semester_n', ['n' => 1]), 2 => __('pondok.diniyah.entry.semester_n', ['n' => 2])])
                            ->selectablePlaceholder(false)
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn () => $this->loadSheet()),
                        Select::make('subject_id')
                            ->label(__('pondok.diniyah.entry.subject'))
                            ->placeholder(__('pondok.diniyah.entry.subject_placeholder'))
                            ->options(fn (): array => ($classroom = $this->classroom()) instanceof Classroom ? $this->subjects($classroom)->pluck('name', 'id')->all() : [])
                            ->visible(fn (): bool => $this->mode() === self::MODE_CLASSROOM)
                            ->live()
                            ->afterStateUpdated(fn () => $this->loadSheet()),
                        Select::make('student_id')
                            ->label(__('pondok.diniyah.entry.student'))
                            ->placeholder(__('pondok.diniyah.entry.student_placeholder'))
                            ->options(fn (): array => ($classroom = $this->classroom()) instanceof Classroom ? $classroom->rosterStudents()->pluck('name', 'id')->all() : [])
                            ->searchable()
                            ->visible(fn (): bool => $this->mode() === self::MODE_STUDENT)
                            ->live()
                            ->afterStateUpdated(fn () => $this->loadSheet()),
                    ]),
                Grid::make(1)->schema(fn (): array => $this->sheetFields()),
            ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $classroom = $this->classroom();
        $subject = $classroom instanceof Classroom && $this->mode() === self::MODE_CLASSROOM ? $this->selectedSubject($classroom) : null;
        $student = $classroom instanceof Classroom && $this->mode() === self::MODE_STUDENT ? $this->selectedStudent($classroom) : null;

        if (! $classroom instanceof Classroom || ($subject === null && $student === null)) {
            $this->refuse($classroom);

            return;
        }

        $data = $this->form->getState();
        $scores = is_array($data['scores'] ?? null) ? $data['scores'] : [];

        if ($subject instanceof DiniyahSubject) {
            $roster = $classroom->rosterStudents()->pluck('id');
            $scores = $roster->filter(fn (int $id): bool => array_key_exists($id, $scores))
                ->mapWithKeys(fn (int $id): array => [$id => [$subject->id => $scores[$id][$subject->id] ?? null]])
                ->all();
            $notes = [];
        } else {
            $scores = [$student->id => (array) ($scores[$student->id] ?? [])];
            $notes = [$student->id => $data['notes'][$student->id] ?? null];
        }

        $this->runShowingPageFormErrors(fn (): int => app(SaveDiniyahGrades::class)->handle(static::user(), $classroom, $data['semester'] ?? null, $scores, $notes));

        $this->loadSheet();

        Notification::make()
            ->title($subject instanceof DiniyahSubject
                ? __('pondok.diniyah.entry.saved_subject', ['subject' => $subject->name, 'classroom' => $classroom->name, 'semester' => $this->semester()])
                : __('pondok.diniyah.entry.saved_student', ['student' => $student->name, 'semester' => $this->semester()]))
            ->success()
            ->send();
    }

    /**
     * Simpan satu sel (santri × mapel terpilih) di mode rombel; baris lain tidak ikut divalidasi.
     */
    public function saveRow(int $studentId): void
    {
        abort_unless(static::canAccess(), 403);

        $classroom = $this->classroom();
        $subject = $classroom instanceof Classroom && $this->mode() === self::MODE_CLASSROOM ? $this->selectedSubject($classroom) : null;
        $student = $subject instanceof DiniyahSubject ? $classroom->rosterStudents()->whereKey($studentId)->first() : null;

        if (! $student instanceof Student) {
            $this->refuse($classroom);

            return;
        }

        $field = "data.scores.{$student->id}.{$subject->id}";
        $this->resetErrorBag($field);

        $this->runShowingPageFormErrors(fn (): int => app(SaveDiniyahGrades::class)->handle(
            static::user(),
            $classroom,
            $this->semester(),
            [$student->id => [$subject->id => data_get($this, $field)]],
            [],
        ));

        Notification::make()->title(__('pondok.diniyah.entry.saved_row', ['student' => $student->name]))->success()->send();
    }

    private function mode(): string
    {
        return ($this->data['mode'] ?? null) === self::MODE_STUDENT ? self::MODE_STUDENT : self::MODE_CLASSROOM;
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
     * Mapel terpilih (mode rombel) bila termasuk mapel aktif jenjang rombel.
     */
    private function selectedSubject(Classroom $classroom): ?DiniyahSubject
    {
        $id = $this->data['subject_id'] ?? null;

        return is_numeric($id) ? $this->subjects($classroom)->firstWhere('id', (int) $id) : null;
    }

    /**
     * Santri terpilih (mode santri) bila termasuk roster rombel.
     */
    private function selectedStudent(Classroom $classroom): ?Student
    {
        $id = $this->data['student_id'] ?? null;

        return is_numeric($id) ? $classroom->rosterStudents()->whereKey((int) $id)->first() : null;
    }

    private function sheetReady(): bool
    {
        $classroom = $this->classroom();

        if (! $classroom instanceof Classroom) {
            return false;
        }

        return $this->mode() === self::MODE_CLASSROOM
            ? $this->selectedSubject($classroom) instanceof DiniyahSubject
            : $this->selectedStudent($classroom) instanceof Student;
    }

    private function refuse(?Classroom $classroom): void
    {
        $this->loadSheet();

        Notification::make()
            ->title($classroom instanceof Classroom ? __('pondok.diniyah.entry.pick_first') : __('pondok.diniyah.errors.forbidden'))
            ->danger()
            ->send();
    }

    /**
     * Ganti mode atau rombel mengosongkan pilihan mapel/santri.
     */
    private function resetTarget(): void
    {
        $this->data['subject_id'] = null;
        $this->data['student_id'] = null;
        $this->loadSheet();
    }

    /**
     * Isi state dengan nilai (dan catatan, mode santri) tersimpan untuk pilihan saat ini; sel kosong = null.
     * Rombel di luar cakupan tidak menyentuh state.
     */
    private function loadSheet(): void
    {
        $classroom = $this->classroom();

        if (! $classroom instanceof Classroom) {
            return;
        }

        $key = ['academic_year_id' => $classroom->academic_year_id, 'semester' => $this->semester()];
        $scores = $notes = [];

        if ($this->mode() === self::MODE_CLASSROOM && ($subject = $this->selectedSubject($classroom)) instanceof DiniyahSubject) {
            $students = $classroom->rosterStudents()->pluck('id');
            $stored = DiniyahGrade::query()->where($key)->where('diniyah_subject_id', $subject->id)->whereIn('student_id', $students)->pluck('score', 'student_id');
            $scores = $students->mapWithKeys(fn (int $id): array => [$id => [$subject->id => $stored->get($id)]])->all();
        }

        if ($this->mode() === self::MODE_STUDENT && ($student = $this->selectedStudent($classroom)) instanceof Student) {
            $stored = DiniyahGrade::query()->where($key)->where('student_id', $student->id)->pluck('score', 'diniyah_subject_id');
            $scores = [$student->id => $this->subjects($classroom)->mapWithKeys(fn (DiniyahSubject $subject): array => [$subject->id => $stored->get($subject->id)])->all()];
            $notes = [$student->id => DiniyahReportNote::query()->where($key)->where('student_id', $student->id)->value('note')];
        }

        $this->data['scores'] = $scores;
        $this->data['notes'] = $notes;
    }

    /**
     * @return array<int, mixed>
     */
    private function sheetFields(): array
    {
        $classroom = $this->classroom();

        if (! $classroom instanceof Classroom) {
            return [$this->emptyState(__('pondok.diniyah.entry.pick_first'))];
        }

        $subjects = $this->subjects($classroom);

        if ($subjects->isEmpty()) {
            return [$this->emptyState(__('pondok.diniyah.entry.no_subjects'))];
        }

        if (! $classroom->rosterStudents()->exists()) {
            return [$this->emptyState(__('pondok.diniyah.entry.no_students'))];
        }

        $key = ['academic_year_id' => $classroom->academic_year_id, 'semester' => $this->semester()];

        if ($this->mode() === self::MODE_STUDENT) {
            $student = $this->selectedStudent($classroom);

            return $student instanceof Student
                ? [$this->studentSheet($classroom, $student, $subjects, DiniyahGrade::query()->where($key)->where('student_id', $student->id)->pluck('score', 'diniyah_subject_id'))]
                : [$this->emptyState(__('pondok.diniyah.entry.pick_first'))];
        }

        $subject = $this->selectedSubject($classroom);

        if (! $subject instanceof DiniyahSubject) {
            return [$this->emptyState(__('pondok.diniyah.entry.pick_first'))];
        }

        $students = $classroom->rosterStudents()->get();
        $stored = DiniyahGrade::query()->where($key)->where('diniyah_subject_id', $subject->id)->whereIn('student_id', $students->modelKeys())->pluck('score', 'student_id');

        return [
            Section::make($subject->name)
                ->description($subject->passing_score === null
                    ? $classroom->name
                    : $classroom->name.' · '.__('pondok.diniyah.entry.passing_hint', ['kkm' => $subject->passing_score]))
                ->schema([
                    Grid::make(['default' => 1, 'lg' => 2])->schema(
                        $students->map(fn (Student $student): TextInput => $this->scoreInput($student, $subject, $stored->get($student->id), withPassingHint: false)
                            ->label($student->name)
                            ->belowLabel(__('pondok.portal_wali.nis', ['nis' => $student->nis]))
                            ->hintAction($this->printAction($classroom, $student)->iconButton())
                            ->afterContent(
                                Action::make("saveRow{$student->id}")
                                    ->label(__('pondok.diniyah.entry.save_row'))
                                    ->action("saveRow({$student->id})")
                                    ->button()
                                    ->outlined(),
                            ))->all(),
                    ),
                ]),
        ];
    }

    /**
     * @param  Collection<int, DiniyahSubject>  $subjects
     * @param  Collection<int, int>  $stored  diniyah_subject_id => nilai tersimpan
     */
    private function studentSheet(Classroom $classroom, Student $student, Collection $subjects, Collection $stored): Section
    {
        return Section::make($student->name)
            ->description(__('pondok.portal_wali.nis', ['nis' => $student->nis]))
            ->headerActions([$this->printAction($classroom, $student)])
            ->schema([
                Grid::make(['default' => 1, 'sm' => 3, 'lg' => 4])->schema(
                    $subjects->map(fn (DiniyahSubject $subject): TextInput => $this->scoreInput($student, $subject, $stored->get($subject->id))
                        ->label($subject->name))->all(),
                ),
                Textarea::make("notes.{$student->id}")
                    ->label(__('pondok.diniyah.entry.note'))
                    ->rows(2)
                    ->maxLength(SaveDiniyahGrades::MAX_NOTE_LENGTH),
            ]);
    }

    /**
     * Isian nilai 0–100 dengan helper KKM (mode santri; mode rombel menaruh KKM di judul lembar) dan
     * penanda "Di bawah KKM" di bawah isian dari nilai tersimpan.
     */
    private function scoreInput(Student $student, DiniyahSubject $subject, ?int $storedScore, bool $withPassingHint = true): TextInput
    {
        $below = $subject->isBelowPassing($storedScore);

        return TextInput::make("scores.{$student->id}.{$subject->id}")
            ->numeric()
            ->integer()
            ->minValue(0)
            ->maxValue(100)
            ->belowContent(array_values(array_filter([
                $withPassingHint && $subject->passing_score !== null ? Text::make(__('pondok.diniyah.entry.passing_hint', ['kkm' => $subject->passing_score])) : null,
                $below ? Text::make(__('pondok.diniyah.entry.below_passing'))->color('danger')->weight(FontWeight::SemiBold) : null,
            ])))
            ->extraInputAttributes(['class' => 'tabular-nums', 'inputmode' => 'numeric']);
    }

    private function printAction(Classroom $classroom, Student $student): Action
    {
        return Action::make("print{$student->id}")
            ->label(__('pondok.diniyah.entry.print'))
            ->icon(Heroicon::OutlinedPrinter)
            ->color('gray')
            ->size('sm')
            ->url(route('filament.admin.akademik.diniyah-report', [
                'student' => $student->id,
                'year' => $classroom->academic_year_id,
                'semester' => $this->semester(),
            ]), shouldOpenInNewTab: true);
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
