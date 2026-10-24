<?php

namespace App\Filament\Resources\DisciplineLevels;

use App\Actions\Kedisiplinan\DeleteDisciplineLevel;
use App\Enums\Module;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Resources\DisciplineLevels\Pages\CreateDisciplineLevel;
use App\Filament\Resources\DisciplineLevels\Pages\EditDisciplineLevel;
use App\Filament\Resources\DisciplineLevels\Pages\ListDisciplineLevels;
use App\Models\DisciplineLevel;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Ambang poin kedisiplinan (F026): hanya label di rekap. Simpan lewat `SaveDisciplineLevel`,
 * hapus lewat `DeleteDisciplineLevel`; tanpa hapus massal.
 */
class DisciplineLevelResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = DisciplineLevel::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?int $navigationSort = 4;

    public static function getModule(): string
    {
        return Module::Kedisiplinan->value;
    }

    public static function getModelLabel(): string
    {
        return __('pondok.kedisiplinan.level.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.kedisiplinan.level.plural');
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
                    ->placeholder(__('pondok.kedisiplinan.level.name_placeholder'))
                    ->maxLength(50)
                    ->required(),
                TextInput::make('min_points')
                    ->label(__('pondok.kedisiplinan.fields.min_points'))
                    ->helperText(__('pondok.kedisiplinan.level.min_points_helper'))
                    ->integer()
                    ->minValue(1)
                    ->maxValue(65535)
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('min_points')
            ->columns([
                TextColumn::make('name')
                    ->label(__('pondok.kedisiplinan.fields.name')),
                TextColumn::make('min_points')
                    ->label(__('pondok.kedisiplinan.fields.min_points'))
                    ->numeric()
                    ->alignEnd()
                    ->extraAttributes(['class' => 'tabular-nums']),
            ])
            ->emptyStateIcon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->emptyStateHeading(__('pondok.kedisiplinan.level.empty'))
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    static::deleteAction(),
                ]),
            ]);
    }

    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription(__('pondok.kedisiplinan.level.delete_description'))
            ->authorize(fn (DisciplineLevel $record): bool => static::canDelete($record))
            ->using(function (DisciplineLevel $record, DeleteDisciplineLevel $delete): bool {
                $delete->handle(DisciplineLevelResource::user(), $record);

                return true;
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDisciplineLevels::route('/'),
            'create' => CreateDisciplineLevel::route('/create'),
            'edit' => EditDisciplineLevel::route('/{record}/edit'),
        ];
    }

    public static function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
