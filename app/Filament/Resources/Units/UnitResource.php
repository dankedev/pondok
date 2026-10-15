<?php

namespace App\Filament\Resources\Units;

use App\Filament\Resources\Units\Pages\ManageUnits;
use App\Models\Unit;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UnitResource extends Resource
{
    protected static ?string $model = Unit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    public static function getModelLabel(): string
    {
        return __('pondok.unit.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.unit.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.master');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label(__('pondok.unit.code'))
                    ->required()
                    ->maxLength(20)
                    ->unique(ignoreRecord: true),
                TextInput::make('name')
                    ->label(__('pondok.unit.name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('sort_order')
                    ->label(__('pondok.unit.sort_order'))
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(255)
                    ->default(0)
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->modifyQueryUsing(fn (Builder $query) => $query->withExists(['feeRates', 'admissionRequirements', 'applicants' => fn (Builder $query) => $query->withoutGlobalScopes()]))
            ->columns([
                TextColumn::make('code')
                    ->label(__('pondok.unit.code'))
                    ->badge()
                    ->searchable(),
                TextColumn::make('name')
                    ->label(__('pondok.unit.name'))
                    ->searchable(),
                TextColumn::make('sort_order')
                    ->label(__('pondok.unit.sort_order'))
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                // Jenjang yang punya tarif, syarat berkas, atau pendaftar (termasuk terhapus) tidak bisa dihapus (FK restrict).
                DeleteAction::make()
                    ->hidden(fn (Unit $record): bool => $record->fee_rates_exists || $record->admission_requirements_exists || $record->applicants_exists),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUnits::route('/'),
        ];
    }
}
