<?php

namespace App\Filament\Resources\DiniyahSubjects;

use App\Enums\Module;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Resources\DiniyahSubjects\Pages\CreateDiniyahSubject;
use App\Filament\Resources\DiniyahSubjects\Pages\EditDiniyahSubject;
use App\Filament\Resources\DiniyahSubjects\Pages\ListDiniyahSubjects;
use App\Models\DiniyahSubject;
use App\Models\Unit;
use BackedEnum;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/**
 * Mapel diniyah per jenjang (F027). Tidak dihapus, cukup dinonaktifkan; cakupan jenjang dari global scope model.
 */
class DiniyahSubjectResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = DiniyahSubject::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?int $navigationSort = 7;

    public static function getModule(): string
    {
        return Module::Akademik->value;
    }

    public static function getModelLabel(): string
    {
        return __('pondok.diniyah.subject.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.diniyah.subject.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.akademik');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('unit_id')
                    ->label(__('pondok.common.unit'))
                    ->options(fn (): array => Unit::query()->accessible()->orderBy('sort_order')->pluck('name', 'id')->all())
                    ->required(),
                TextInput::make('name')
                    ->label(__('pondok.diniyah.subject.name'))
                    ->maxLength(100)
                    ->required()
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('unit_id', $get('unit_id')))
                    ->validationMessages(['unique' => __('pondok.diniyah.subject.unique')]),
                TextInput::make('sort_order')
                    ->label(__('pondok.diniyah.subject.sort_order'))
                    ->helperText(__('pondok.diniyah.subject.sort_order_helper'))
                    ->integer()
                    ->minValue(0)
                    ->maxValue(255)
                    ->default(1)
                    ->required(),
                Toggle::make('is_active')
                    ->label(__('pondok.common.is_active'))
                    ->helperText(__('pondok.diniyah.subject.is_active_helper'))
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('unit'))
            ->defaultSort(fn ($query) => $query->orderBy('unit_id')->orderBy('sort_order')->orderBy('name'))
            ->columns([
                TextColumn::make('unit.name')
                    ->label(__('pondok.common.unit')),
                TextColumn::make('name')
                    ->label(__('pondok.diniyah.subject.name'))
                    ->searchable(),
                TextColumn::make('sort_order')
                    ->label(__('pondok.diniyah.subject.sort_order'))
                    ->numeric()
                    ->alignEnd()
                    ->extraAttributes(['class' => 'tabular-nums']),
                IconColumn::make('is_active')
                    ->label(__('pondok.common.is_active'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('unit_id')
                    ->label(__('pondok.common.unit'))
                    ->options(fn (): array => Unit::query()->accessible()->orderBy('sort_order')->pluck('name', 'id')->all()),
                TernaryFilter::make('is_active')
                    ->label(__('pondok.common.is_active')),
            ])
            ->emptyStateIcon(Heroicon::OutlinedBookOpen)
            ->emptyStateHeading(__('pondok.diniyah.subject.empty'))
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDiniyahSubjects::route('/'),
            'create' => CreateDiniyahSubject::route('/create'),
            'edit' => EditDiniyahSubject::route('/{record}/edit'),
        ];
    }
}
