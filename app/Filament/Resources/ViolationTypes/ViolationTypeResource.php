<?php

namespace App\Filament\Resources\ViolationTypes;

use App\Enums\Module;
use App\Enums\ViolationCategory;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Resources\ViolationTypes\Pages\CreateViolationType;
use App\Filament\Resources\ViolationTypes\Pages\EditViolationType;
use App\Filament\Resources\ViolationTypes\Pages\ListViolationTypes;
use App\Models\User;
use App\Models\ViolationType;
use BackedEnum;
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
use Illuminate\Support\Facades\Auth;

/**
 * Katalog jenis pelanggaran berpoin (F026). Simpan lewat `SaveViolationType`; tidak dihapus, cukup dinonaktifkan.
 */
class ViolationTypeResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = ViolationType::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?int $navigationSort = 3;

    public static function getModule(): string
    {
        return Module::Kedisiplinan->value;
    }

    public static function getModelLabel(): string
    {
        return __('pondok.kedisiplinan.violation_type.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.kedisiplinan.violation_type.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.kedisiplinan');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label(__('pondok.kedisiplinan.fields.name'))
                    ->maxLength(100)
                    ->required(),
                Select::make('category')
                    ->label(__('pondok.kedisiplinan.fields.category'))
                    ->options(static::categoryOptions())
                    ->required(),
                TextInput::make('points')
                    ->label(__('pondok.kedisiplinan.fields.points'))
                    ->helperText(__('pondok.kedisiplinan.violation_type.points_helper'))
                    ->integer()
                    ->minValue(1)
                    ->maxValue(ViolationType::MAX_POINTS)
                    ->required(),
                Toggle::make('is_active')
                    ->label(__('pondok.kedisiplinan.fields.is_active'))
                    ->helperText(__('pondok.kedisiplinan.violation_type.is_active_helper'))
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort(fn ($query) => $query->orderBy('points')->orderBy('name'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('pondok.kedisiplinan.fields.name'))
                    ->searchable(),
                TextColumn::make('category')
                    ->label(__('pondok.kedisiplinan.fields.category'))
                    ->badge()
                    ->formatStateUsing(fn (ViolationCategory $state): string => $state->label())
                    ->color(fn (ViolationCategory $state): string => $state->color()),
                TextColumn::make('points')
                    ->label(__('pondok.kedisiplinan.fields.points'))
                    ->numeric()
                    ->alignEnd()
                    ->sortable()
                    ->extraAttributes(['class' => 'tabular-nums']),
                IconColumn::make('is_active')
                    ->label(__('pondok.kedisiplinan.fields.is_active'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label(__('pondok.kedisiplinan.fields.category'))
                    ->options(static::categoryOptions()),
                TernaryFilter::make('is_active')
                    ->label(__('pondok.kedisiplinan.fields.is_active')),
            ])
            ->emptyStateIcon(Heroicon::OutlinedListBullet)
            ->emptyStateHeading(__('pondok.kedisiplinan.violation_type.empty'))
            ->recordActions([
                EditAction::make(),
            ]);
    }

    /**
     * @return array<string, string>
     */
    public static function categoryOptions(): array
    {
        return collect(ViolationCategory::cases())
            ->mapWithKeys(fn (ViolationCategory $category): array => [$category->value => $category->label()])
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListViolationTypes::route('/'),
            'create' => CreateViolationType::route('/create'),
            'edit' => EditViolationType::route('/{record}/edit'),
        ];
    }

    public static function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
