<?php

namespace App\Filament\Pages;

use App\Actions\Prestasi\SummarizeAchievements;
use App\Actions\Prestasi\SummarizeStudentAchievements;
use App\Filament\Resources\Achievements\AchievementResource;
use App\Models\AcademicYear;
use App\Models\Achievement;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Unit;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
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
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Gate;

/**
 * Rekap prestasi (F015): matriks kategori × tingkat dan daftar santri
 * berprestasi per tahun ajaran. Bawaan hanya prestasi terverifikasi; toggle
 * "Termasuk yang belum diverifikasi" hanya untuk pengelola (boleh `create`
 * atau `verify` prestasi), nilai kiriman langsung tanpa hak itu diabaikan.
 *
 * @property-read Schema $form
 */
class AchievementRecap extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'achievement-recap';

    protected string $view = 'filament.pages.achievement-recap';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Setting::moduleEnabled('prestasi') && Gate::allows('viewAny', Achievement::class);
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
        return __('pondok.navigation.prestasi');
    }

    public static function getNavigationLabel(): string
    {
        return __('pondok.achievement_recap.navigation');
    }

    public function getTitle(): string
    {
        return __('pondok.achievement_recap.title');
    }

    public function getSubheading(): ?string
    {
        return $this->includesUnverified()
            ? __('pondok.achievement_recap.including_unverified_note')
            : __('pondok.achievement_recap.verified_only_note');
    }

    public function mount(): void
    {
        $this->form->fill([
            'academic_year_id' => AcademicYear::current()?->id,
            'unit_id' => null,
            'include_unverified' => false,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Grid::make(['default' => 1, 'md' => 3])->schema([
                    Select::make('academic_year_id')
                        ->label(__('pondok.achievement.academic_year'))
                        ->placeholder(__('pondok.achievement_recap.all_years'))
                        ->options(fn (): array => AcademicYear::query()->orderByDesc('starts_on')->pluck('name', 'id')->all())
                        ->live(),
                    Select::make('unit_id')
                        ->label(__('pondok.common.unit'))
                        ->options(fn (): array => Unit::query()->accessible()->orderBy('sort_order')->pluck('name', 'id')->all())
                        ->live(),
                    Toggle::make('include_unverified')
                        ->label(__('pondok.achievement_recap.include_unverified'))
                        ->visible(fn (): bool => static::canIncludeUnverified())
                        ->inline(false)
                        ->live(),
                ]),
            ]);
    }

    /**
     * Pengelola prestasi (boleh mencatat atau memverifikasi).
     */
    public static function canIncludeUnverified(): bool
    {
        $user = auth()->user();

        return $user !== null && (Gate::allows('create', Achievement::class) || $user->can('verify:achievement'));
    }

    public function includesUnverified(): bool
    {
        return static::canIncludeUnverified() && (bool) ($this->data['include_unverified'] ?? false);
    }

    public function academicYearId(): ?int
    {
        $value = $this->data['academic_year_id'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    public function unitId(): ?int
    {
        $value = $this->data['unit_id'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return array{total: int, participations: int, by_category: array<string, int>, by_level: array<string, int>, matrix: array<string, array<string, int>>}
     */
    public function getSummary(): array
    {
        return app(SummarizeAchievements::class)->handle($this->academicYearId(), $this->unitId(), ! $this->includesUnverified());
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('pondok.achievement_recap.students'))
            ->query(fn (): Builder => $this->studentsQuery())
            ->columns([
                TextColumn::make('nis')
                    ->label(__('pondok.student.nis')),
                TextColumn::make('name')
                    ->label(__('pondok.common.name')),
                TextColumn::make('unit.name')
                    ->label(__('pondok.common.unit')),
                TextColumn::make('achievement_count')
                    ->label(__('pondok.achievement_recap.achievement_count'))
                    ->numeric()
                    ->alignEnd()
                    ->extraAttributes(['class' => 'tabular-nums']),
                TextColumn::make('highest_level')
                    ->label(__('pondok.achievement_recap.highest_level'))
                    ->state(function (Student $record): ?string {
                        $level = SummarizeStudentAchievements::highestLevel($record->achievements->pluck('level'));

                        return $level === null ? null : __("pondok.achievement.levels.{$level}");
                    })
                    ->placeholder('-'),
            ])
            ->recordActions([
                Action::make('view_achievements')
                    ->label(__('pondok.achievement.view_achievements'))
                    ->icon(Heroicon::OutlinedTrophy)
                    ->color('gray')
                    ->visible(fn (): bool => AchievementResource::canViewAny())
                    ->url(fn (Student $record): string => AchievementResource::getUrl('index', ['filters' => array_filter([
                        'student' => ['value' => $record->id],
                        'academic_year_id' => ['value' => $this->academicYearId()],
                    ])])),
            ])
            ->emptyStateIcon(Heroicon::OutlinedTrophy)
            ->emptyStateHeading(__('pondok.achievement_recap.empty'));
    }

    /**
     * Santri terlihat (termasuk terarsip) yang punya prestasi sesuai filter, urut jumlah lalu nama.
     */
    private function studentsQuery(): Builder
    {
        $constraint = function (Builder|BelongsToMany $query): void {
            $query
                ->when($this->academicYearId() !== null, fn ($query) => $query->where('achievements.academic_year_id', $this->academicYearId()))
                ->when(! $this->includesUnverified(), fn ($query) => $query->whereNotNull('achievements.verified_by'));
        };

        return Student::query()
            ->withTrashed()
            ->when($this->unitId() !== null, fn (Builder $query) => $query->where('students.unit_id', $this->unitId()))
            ->whereHas('achievements', $constraint)
            ->withCount(['achievements as achievement_count' => $constraint])
            ->with(['unit', 'achievements' => $constraint])
            ->orderByDesc('achievement_count')
            ->orderBy('name')
            ->orderBy('students.id');
    }
}
