<?php

namespace App\Filament\Resources\FeeRates;

use App\Actions\Keuangan\SaveFeeRate;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ShowsActionErrorsOnForm;
use App\Filament\Resources\FeeRates\Pages\ManageFeeRates;
use App\Models\AcademicYear;
use App\Models\Cohort;
use App\Models\Dormitory;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Unit;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tarif per jenis biaya, jenjang, angkatan, dan asrama, berlaku mulai tahun
 * ajaran tertentu. Simpan selalu lewat Action `SaveFeeRate`. Tarif tidak
 * dihapus: dikoreksi lewat edit atau diganti tarif tahun berikutnya.
 */
class FeeRateResource extends Resource
{
    use BelongsToModule, ShowsActionErrorsOnForm;

    protected static ?string $model = FeeRate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 3;

    public static function getModule(): string
    {
        return 'keuangan';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.fee_rate.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.fee_rate.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.keuangan');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('unit_id', Unit::query()->accessible()->select('id'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('fee_type_id')
                    ->label(__('pondok.fee_rate.fee_type'))
                    ->options(fn (?FeeRate $record): array => FeeType::query()
                        ->where(fn (Builder $query) => $query
                            ->where('is_active', true)
                            ->when($record, fn (Builder $query, FeeRate $record) => $query->orWhereKey($record->fee_type_id)))
                        ->orderBy('priority')
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required()
                    ->live(),
                Select::make('effective_from_year_id')
                    ->label(__('pondok.fee_rate.effective_from_year'))
                    ->helperText(__('pondok.fee_rate.effective_from_year_helper'))
                    ->options(fn (): array => AcademicYear::query()->orderByDesc('starts_on')->pluck('name', 'id')->all())
                    ->default(fn (): ?int => AcademicYear::current()?->id)
                    ->required(),
                Select::make('unit_id')
                    ->label(__('pondok.fee_rate.unit'))
                    ->options(fn (): array => Unit::query()->accessible()->orderBy('sort_order')->pluck('name', 'id')->all())
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('cohort_id', null)),
                Select::make('cohort_id')
                    ->label(__('pondok.fee_rate.cohort'))
                    ->placeholder(__('pondok.fee_rate.all_cohorts'))
                    ->options(fn (Get $get): array => blank($get('unit_id')) ? [] : Cohort::query()
                        ->where('unit_id', $get('unit_id'))
                        ->orderByDesc('entry_year')
                        ->pluck('name', 'id')
                        ->all()),
                Select::make('dormitory_id')
                    ->label(__('pondok.fee_rate.dormitory'))
                    ->placeholder(__('pondok.fee_rate.all_dormitories'))
                    ->options(fn (?FeeRate $record): array => Dormitory::query()
                        ->where(fn (Builder $query) => $query
                            ->where('is_active', true)
                            ->when($record?->dormitory_id, fn (Builder $query, int $id) => $query->orWhereKey($id)))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    // Jenis biaya khusus nonmukim tidak dibedakan per asrama.
                    ->hidden(fn (Get $get): bool => filled($get('fee_type_id'))
                        && FeeType::query()->whereKey($get('fee_type_id'))->value('applies_to') === FeeType::APPLIES_TO_NON_RESIDENT),
                TextInput::make('amount')
                    ->label(__('pondok.fee_rate.amount'))
                    ->prefix('Rp')
                    ->integer()
                    ->minValue(0)
                    ->required(),
                Text::make(__('pondok.fee_rate.new_year_helper'))
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query) => $query
                ->orderBy(FeeType::query()->select('name')->whereColumn('fee_types.id', 'fee_rates.fee_type_id'))
                ->orderBy(Unit::query()->select('sort_order')->whereColumn('units.id', 'fee_rates.unit_id'))
                ->orderByDesc(AcademicYear::query()->select('starts_on')->whereColumn('academic_years.id', 'fee_rates.effective_from_year_id'))
                ->orderBy('fee_rates.id'))
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['feeType', 'unit', 'cohort', 'dormitory', 'effectiveFromYear']))
            ->columns([
                TextColumn::make('feeType.name')
                    ->label(__('pondok.fee_rate.fee_type'))
                    ->searchable(),
                TextColumn::make('unit.name')
                    ->label(__('pondok.fee_rate.unit')),
                TextColumn::make('cohort.name')
                    ->label(__('pondok.fee_rate.cohort'))
                    ->placeholder(__('pondok.fee_rate.all_cohorts')),
                TextColumn::make('dormitory.name')
                    ->label(__('pondok.fee_rate.dormitory'))
                    ->placeholder(__('pondok.fee_rate.all_dormitories')),
                TextColumn::make('effectiveFromYear.name')
                    ->label(__('pondok.fee_rate.effective_from_year')),
                TextColumn::make('amount')
                    ->label(__('pondok.fee_rate.amount'))
                    ->money('IDR', locale: 'id', decimalPlaces: 0)
                    ->alignEnd()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('fee_type_id')
                    ->label(__('pondok.fee_rate.fee_type'))
                    ->relationship('feeType', 'name'),
                SelectFilter::make('unit_id')
                    ->label(__('pondok.fee_rate.unit'))
                    ->relationship('unit', 'name', fn (Builder $query) => $query->accessible()),
                SelectFilter::make('effective_from_year_id')
                    ->label(__('pondok.fee_rate.effective_from_year'))
                    ->relationship('effectiveFromYear', 'name', fn (Builder $query) => $query->orderByDesc('starts_on')),
            ])
            ->emptyStateHeading(__('pondok.fee_rate.empty'))
            ->recordActions([
                EditAction::make()
                    ->using(fn (FeeRate $record, array $data, SaveFeeRate $saveFeeRate, Action $action): FeeRate => static::saveShowingFormErrors(
                        $action,
                        // Gelombang PSB belum tampil di form (F009); pertahankan nilainya.
                        fn (): FeeRate => $saveFeeRate->handle(['admission_wave_id' => $record->admission_wave_id, ...$data], $record),
                    )),
            ]);
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()
            ->using(fn (array $data, SaveFeeRate $saveFeeRate, Action $action): FeeRate => static::saveShowingFormErrors(
                $action,
                fn (): FeeRate => $saveFeeRate->handle($data),
            ));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageFeeRates::route('/'),
        ];
    }
}
