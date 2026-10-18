<?php

namespace App\Filament\Resources\Cohorts;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Resources\Cohorts\Pages\ManageCohorts;
use App\Models\Cohort;
use App\Models\Unit;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

class CohortResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Cohort::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 3;

    public static function getModule(): string
    {
        return 'akademik';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.cohort.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.cohort.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.akademik');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('unit_id', Unit::query()->accessible()->select('id'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('unit_id')
                    ->label(__('pondok.common.unit'))
                    ->relationship('unit', 'name', fn (Builder $query) => $query->accessible()->orderBy('sort_order'))
                    ->required()
                    ->live(),
                TextInput::make('entry_year')
                    ->label(__('pondok.cohort.entry_year'))
                    ->integer()
                    ->minValue(1900)
                    ->maxValue(2999)
                    ->default(now()->year)
                    ->required()
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('unit_id', $get('unit_id')),
                    )
                    ->validationMessages(['unique' => __('pondok.cohort.unique')]),
                TextInput::make('name')
                    ->label(__('pondok.common.name'))
                    ->helperText(__('pondok.cohort.name_helper'))
                    ->required()
                    ->maxLength(100),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('entry_year', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('unit')->withCount('students')->withExists('feeRates'))
            ->columns([
                TextColumn::make('unit.name')
                    ->label(__('pondok.common.unit'))
                    ->sortable(),
                TextColumn::make('entry_year')
                    ->label(__('pondok.cohort.entry_year'))
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('pondok.common.name'))
                    ->searchable(),
                TextColumn::make('students_count')
                    ->label(__('pondok.cohort.students_count')),
            ])
            ->filters([
                SelectFilter::make('unit_id')
                    ->label(__('pondok.common.unit'))
                    ->relationship('unit', 'name', fn (Builder $query) => $query->accessible()),
            ])
            ->recordActions([
                EditAction::make(),
                // Santri dan tarif menahan penghapusan (FK restrict).
                DeleteAction::make()
                    ->hidden(fn (Cohort $record): bool => $record->students_count > 0 || $record->fee_rates_exists),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCohorts::route('/'),
        ];
    }
}
