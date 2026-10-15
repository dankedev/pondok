<?php

namespace App\Filament\Resources\Scholarships;

use App\Actions\Keuangan\SaveScholarship;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ShowsActionErrorsOnForm;
use App\Filament\Resources\Scholarships\Pages\ManageScholarships;
use App\Models\FeeOverride;
use App\Models\Scholarship;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Program beasiswa beserta donaturnya. Simpan selalu lewat Action
 * `SaveScholarship`. Program tidak dihapus, cukup dinonaktifkan.
 */
class ScholarshipResource extends Resource
{
    use BelongsToModule, ShowsActionErrorsOnForm;

    protected static ?string $model = Scholarship::class;

    // Design guide §3: kapital hanya di awal kalimat.
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 4;

    public static function getModule(): string
    {
        return 'keuangan';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.scholarship.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.scholarship.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.keuangan');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label(__('pondok.scholarship.name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('sponsor')
                    ->label(__('pondok.scholarship.sponsor'))
                    ->helperText(__('pondok.scholarship.sponsor_helper'))
                    ->maxLength(255),
                Select::make('mode')
                    ->label(__('pondok.scholarship.mode'))
                    ->options(static::modeOptions())
                    ->default(FeeOverride::MODE_PERCENT)
                    ->required()
                    ->live(),
                static::valueInput('default_value')
                    ->label(__('pondok.scholarship.default_value'))
                    ->helperText(__('pondok.scholarship.default_value_helper')),
                Toggle::make('is_active')
                    ->label(__('pondok.scholarship.is_active'))
                    ->helperText(__('pondok.scholarship.is_active_helper'))
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('feeOverrides'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('pondok.scholarship.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sponsor')
                    ->label(__('pondok.scholarship.sponsor'))
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('default_value')
                    ->label(__('pondok.scholarship.default_value_label'))
                    ->state(fn (Scholarship $record): string => $record->defaultValueLabel())
                    ->alignEnd()
                    ->extraAttributes(['class' => 'tabular-nums']),
                TextColumn::make('fee_overrides_count')
                    ->label(__('pondok.scholarship.overrides_count'))
                    ->numeric(locale: 'id')
                    ->alignEnd()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('pondok.scholarship.is_active'))
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('pondok.scholarship.is_active')),
            ])
            ->emptyStateHeading(__('pondok.scholarship.empty'))
            ->recordActions([
                EditAction::make()
                    ->using(fn (Scholarship $record, array $data, SaveScholarship $saveScholarship, Action $action): Scholarship => static::saveShowingFormErrors(
                        $action,
                        fn (): Scholarship => $saveScholarship->handle($data, $record),
                    )),
            ]);
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()
            ->using(fn (array $data, SaveScholarship $saveScholarship, Action $action): Scholarship => static::saveShowingFormErrors(
                $action,
                fn (): Scholarship => $saveScholarship->handle($data),
            ));
    }

    /**
     * @return array<string, string>
     */
    public static function modeOptions(): array
    {
        return collect(FeeOverride::MODES)
            ->mapWithKeys(fn (string $mode): array => [$mode => __("pondok.fee_override.modes.{$mode}")])
            ->all();
    }

    /**
     * Input nilai keringanan: rupiah untuk nominal/potongan, persen 0-100 untuk mode persen.
     */
    public static function valueInput(string $name): TextInput
    {
        $isPercent = fn (Get $get): bool => $get('mode') === FeeOverride::MODE_PERCENT;

        return TextInput::make($name)
            ->integer()
            ->minValue(0)
            ->maxValue(fn (Get $get): ?int => $isPercent($get) ? 100 : null)
            ->prefix(fn (Get $get): ?string => $isPercent($get) ? null : 'Rp')
            ->suffix(fn (Get $get): ?string => $isPercent($get) ? '%' : null)
            ->required();
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageScholarships::route('/'),
        ];
    }
}
