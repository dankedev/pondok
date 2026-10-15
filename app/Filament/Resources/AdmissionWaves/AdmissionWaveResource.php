<?php

namespace App\Filament\Resources\AdmissionWaves;

use App\Actions\Psb\SaveAdmissionWave;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ShowsActionErrorsOnForm;
use App\Filament\Resources\AdmissionWaves\Pages\ManageAdmissionWaves;
use App\Models\AcademicYear;
use App\Models\AdmissionWave;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Gelombang PSB per tahun ajaran tujuan. Simpan selalu lewat Action
 * `SaveAdmissionWave`. Gelombang tidak dihapus, cukup ditutup.
 */
class AdmissionWaveResource extends Resource
{
    use BelongsToModule, ShowsActionErrorsOnForm;

    protected static ?string $model = AdmissionWave::class;

    // Design guide §3: kapital hanya di awal kalimat.
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 1;

    public static function getModule(): string
    {
        return 'psb';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.admission_wave.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.admission_wave.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.psb');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('academic_year_id')
                    ->label(__('pondok.admission_wave.academic_year'))
                    ->options(fn (): array => AcademicYear::query()->orderByDesc('starts_on')->pluck('name', 'id')->all())
                    ->default(fn (): ?int => static::nextAcademicYearId())
                    ->required(),
                TextInput::make('name')
                    ->label(__('pondok.admission_wave.name'))
                    ->required()
                    ->maxLength(100),
                DatePicker::make('opens_on')
                    ->label(__('pondok.admission_wave.opens_on'))
                    ->required(),
                DatePicker::make('closes_on')
                    ->label(__('pondok.admission_wave.closes_on'))
                    ->required(),
                TextInput::make('quota')
                    ->label(__('pondok.admission_wave.quota'))
                    ->helperText(__('pondok.admission_wave.quota_helper'))
                    ->integer()
                    ->minValue(1)
                    ->maxValue(SaveAdmissionWave::MAX_QUOTA),
                Toggle::make('is_open')
                    ->label(__('pondok.admission_wave.is_open'))
                    ->helperText(__('pondok.admission_wave.is_open_helper'))
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query) => $query
                ->orderByDesc(AcademicYear::query()->select('starts_on')->whereColumn('academic_years.id', 'admission_waves.academic_year_id'))
                ->orderBy('admission_waves.opens_on')
                ->orderBy('admission_waves.id'))
            ->modifyQueryUsing(fn (Builder $query) => $query->with('academicYear'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('pondok.admission_wave.name'))
                    ->searchable(),
                TextColumn::make('academicYear.name')
                    ->label(__('pondok.admission_wave.academic_year')),
                TextColumn::make('opens_on')
                    ->label(__('pondok.admission_wave.opens_on'))
                    ->date('j M Y'),
                TextColumn::make('closes_on')
                    ->label(__('pondok.admission_wave.closes_on'))
                    ->date('j M Y'),
                TextColumn::make('quota')
                    ->label(__('pondok.admission_wave.quota'))
                    ->numeric(locale: 'id')
                    ->placeholder(__('pondok.admission_wave.unlimited'))
                    ->alignEnd(),
                IconColumn::make('is_open')
                    ->label(__('pondok.admission_wave.is_open'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('academic_year_id')
                    ->label(__('pondok.admission_wave.academic_year'))
                    ->relationship('academicYear', 'name', fn (Builder $query) => $query->orderByDesc('starts_on')),
                TernaryFilter::make('is_open')
                    ->label(__('pondok.admission_wave.is_open')),
            ])
            ->emptyStateHeading(__('pondok.admission_wave.empty'))
            ->recordActions([
                EditAction::make()
                    ->using(fn (AdmissionWave $record, array $data, SaveAdmissionWave $saveAdmissionWave, Action $action): AdmissionWave => static::saveShowingFormErrors(
                        $action,
                        fn (): AdmissionWave => $saveAdmissionWave->handle($data, $record),
                    )),
            ]);
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()
            ->using(fn (array $data, SaveAdmissionWave $saveAdmissionWave, Action $action): AdmissionWave => static::saveShowingFormErrors(
                $action,
                fn (): AdmissionWave => $saveAdmissionWave->handle($data),
            ));
    }

    /**
     * Tahun ajaran pertama setelah tahun aktif: gelombang PSB menerima santri untuk tahun berikutnya.
     */
    public static function nextAcademicYearId(): ?int
    {
        $current = AcademicYear::current();

        if ($current === null) {
            return null;
        }

        return AcademicYear::query()
            ->where('starts_on', '>', $current->starts_on)
            ->orderBy('starts_on')
            ->value('id');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAdmissionWaves::route('/'),
        ];
    }
}
