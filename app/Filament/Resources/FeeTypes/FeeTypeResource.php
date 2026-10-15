<?php

namespace App\Filament\Resources\FeeTypes;

use App\Actions\Keuangan\SaveFeeType;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ShowsActionErrorsOnForm;
use App\Filament\Resources\FeeTypes\Pages\ManageFeeTypes;
use App\Models\FeeType;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
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
 * Jenis biaya. Simpan selalu lewat Action `SaveFeeType`. Data keuangan tidak
 * dihapus: jenis biaya cukup dinonaktifkan.
 */
class FeeTypeResource extends Resource
{
    use BelongsToModule, ShowsActionErrorsOnForm;

    protected static ?string $model = FeeType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    public static function getModule(): string
    {
        return 'keuangan';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.fee_type.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.fee_type.plural');
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
                TextInput::make('code')
                    ->label(__('pondok.fee_type.code'))
                    ->helperText(__('pondok.fee_type.code_helper'))
                    ->required()
                    ->maxLength(30),
                TextInput::make('name')
                    ->label(__('pondok.fee_type.name'))
                    ->helperText(__('pondok.fee_type.name_helper'))
                    ->required()
                    ->maxLength(255),
                Select::make('frequency')
                    ->label(__('pondok.fee_type.frequency'))
                    ->options(__('pondok.fee_type.frequencies'))
                    ->required(),
                Select::make('applies_to')
                    ->label(__('pondok.fee_type.applies_to'))
                    ->options(__('pondok.fee_type.applies_to_options'))
                    ->default(FeeType::APPLIES_TO_ALL)
                    ->selectablePlaceholder(false)
                    ->required(),
                TextInput::make('billing_group')
                    ->label(__('pondok.fee_type.billing_group'))
                    ->helperText(__('pondok.fee_type.billing_group_helper'))
                    ->default('BULANAN')
                    ->required()
                    ->maxLength(30),
                TextInput::make('priority')
                    ->label(__('pondok.fee_type.priority'))
                    ->helperText(__('pondok.fee_type.priority_helper'))
                    ->integer()
                    ->minValue(0)
                    ->maxValue(65535)
                    ->default(FeeType::DEFAULT_PRIORITY)
                    ->required(),
                Toggle::make('is_active')
                    ->label(__('pondok.common.is_active'))
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query) => $query->orderBy('priority')->orderBy('code'))
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('rates'))
            ->columns([
                TextColumn::make('code')
                    ->label(__('pondok.fee_type.code'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('pondok.fee_type.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('frequency')
                    ->label(__('pondok.fee_type.frequency'))
                    ->formatStateUsing(fn (string $state): string => __("pondok.fee_type.frequencies.{$state}")),
                TextColumn::make('applies_to')
                    ->label(__('pondok.fee_type.applies_to'))
                    ->formatStateUsing(fn (string $state): string => __("pondok.fee_type.applies_to_options.{$state}")),
                TextColumn::make('billing_group')
                    ->label(__('pondok.fee_type.billing_group')),
                TextColumn::make('priority')
                    ->label(__('pondok.fee_type.priority'))
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('pondok.common.is_active'))
                    ->boolean(),
                TextColumn::make('rates_count')
                    ->label(__('pondok.fee_type.rates_count'))
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('frequency')
                    ->label(__('pondok.fee_type.frequency'))
                    ->options(__('pondok.fee_type.frequencies')),
                SelectFilter::make('applies_to')
                    ->label(__('pondok.fee_type.applies_to'))
                    ->options(__('pondok.fee_type.applies_to_options')),
                TernaryFilter::make('is_active')
                    ->label(__('pondok.common.is_active')),
            ])
            ->emptyStateHeading(__('pondok.fee_type.empty'))
            ->recordActions([
                EditAction::make()
                    ->using(fn (FeeType $record, array $data, SaveFeeType $saveFeeType, Action $action): FeeType => static::saveShowingFormErrors(
                        $action,
                        fn (): FeeType => $saveFeeType->handle($data, $record),
                    )),
            ]);
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()
            ->using(fn (array $data, SaveFeeType $saveFeeType, Action $action): FeeType => static::saveShowingFormErrors(
                $action,
                fn (): FeeType => $saveFeeType->handle($data),
            ));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageFeeTypes::route('/'),
        ];
    }
}
