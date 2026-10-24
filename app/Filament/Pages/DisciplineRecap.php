<?php

namespace App\Filament\Pages;

use App\Actions\Kedisiplinan\SummarizeDisciplinePoints;
use App\Enums\Module;
use App\Filament\Resources\StudentViolations\StudentViolationResource;
use App\Models\AcademicYear;
use App\Models\Unit;
use BackedEnum;
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

/**
 * Rekap poin kedisiplinan per santri untuk satu tahun ajaran (F026), dari `SummarizeDisciplinePoints`.
 * Data mengikuti cakupan user (musyrif hanya santri binaannya). Tanpa tahun ajaran aktif, tabel kosong.
 *
 * @property-read Schema $form
 */
class DisciplineRecap extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'discipline-recap';

    protected string $view = 'filament.pages.discipline-recap';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function getModule(): string
    {
        return Module::Kedisiplinan->value;
    }

    public static function canAccess(): bool
    {
        return StudentViolationResource::canViewAny();
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
        return __('pondok.navigation.kedisiplinan');
    }

    public static function getNavigationLabel(): string
    {
        return __('pondok.kedisiplinan.recap.navigation');
    }

    public function getTitle(): string
    {
        return __('pondok.kedisiplinan.recap.title');
    }

    public function mount(): void
    {
        $this->form->fill([
            'academic_year_id' => AcademicYear::current()?->id,
            'unit_id' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    Select::make('academic_year_id')
                        ->label(__('pondok.kedisiplinan.violation.academic_year'))
                        ->options(fn (): array => AcademicYear::query()->orderByDesc('starts_on')->pluck('name', 'id')->all())
                        ->live(),
                    Select::make('unit_id')
                        ->label(__('pondok.kedisiplinan.violation.unit'))
                        ->placeholder(__('pondok.kedisiplinan.recap.all_units'))
                        ->options(fn (): array => Unit::query()->accessible()->orderBy('sort_order')->pluck('name', 'id')->all())
                        ->live(),
                ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('pondok.kedisiplinan.recap.students'))
            ->records(fn (): array => $this->rows())
            ->paginated(false)
            ->columns([
                TextColumn::make('nis')
                    ->label(__('pondok.student.nis'))
                    ->extraAttributes(['class' => 'tabular-nums']),
                TextColumn::make('name')
                    ->label(__('pondok.common.name')),
                TextColumn::make('unit')
                    ->label(__('pondok.kedisiplinan.violation.unit')),
                TextColumn::make('violations_count')
                    ->label(__('pondok.kedisiplinan.recap.violations_count'))
                    ->numeric()
                    ->alignEnd()
                    ->extraAttributes(['class' => 'tabular-nums']),
                TextColumn::make('total_points')
                    ->label(__('pondok.kedisiplinan.recap.total_points'))
                    ->numeric()
                    ->alignEnd()
                    ->weight('semibold')
                    ->extraAttributes(['class' => 'tabular-nums']),
                TextColumn::make('level')
                    ->label(__('pondok.kedisiplinan.recap.level'))
                    ->badge()
                    ->color('warning')
                    ->placeholder('-'),
            ])
            ->emptyStateIcon(Heroicon::OutlinedChartBar)
            ->emptyStateHeading(fn (): string => $this->academicYearId() === null
                ? __('pondok.kedisiplinan.recap.pick_year')
                : __('pondok.kedisiplinan.recap.empty'));
    }

    public function academicYearId(): ?int
    {
        $value = $this->data['academic_year_id'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Baris rekap dikunci `student_id`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rows(): array
    {
        $yearId = $this->academicYearId();

        if ($yearId === null) {
            return [];
        }

        $unitId = $this->data['unit_id'] ?? null;

        return app(SummarizeDisciplinePoints::class)
            ->handle(StudentViolationResource::user(), $yearId, is_numeric($unitId) ? (int) $unitId : null)
            ->mapWithKeys(fn (array $row): array => [$row['student_id'] => [
                'nis' => $row['student']->nis,
                'name' => $row['student']->name,
                'unit' => $row['student']->unit?->name,
                'violations_count' => $row['violations_count'],
                'total_points' => $row['total_points'],
                'level' => $row['level'],
            ]])
            ->all();
    }
}
