<?php

namespace App\Filament\Resources\Categories;

use App\Actions\Konten\DeleteCategory;
use App\Actions\Konten\SaveCategory;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ShowsActionErrorsOnForm;
use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Models\Category;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Kategori berita dan galeri (F016). Simpan lewat `SaveCategory`; kategori yang
 * dipakai (post termasuk yang terhapus, atau album) tidak bisa dihapus dan
 * jenisnya dikunci.
 */
class CategoryResource extends Resource
{
    use BelongsToModule, ShowsActionErrorsOnForm;

    protected static ?string $model = Category::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 6;

    public static function getModule(): string
    {
        return 'konten';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.category.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.category.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.situs');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->label(__('pondok.category.type'))
                    ->options(__('pondok.category.types'))
                    ->default(Category::TYPE_POST)
                    ->required()
                    ->disabled(fn (?Category $record): bool => $record?->isInUse() ?? false)
                    ->helperText(fn (?Category $record): ?string => ($record?->isInUse() ?? false) ? __('pondok.category.type_locked_helper') : null),
                TextInput::make('name')
                    ->label(__('pondok.category.name'))
                    ->required()
                    ->maxLength(Category::NAME_MAX_LENGTH),
                TextInput::make('slug')
                    ->label(__('pondok.category.slug'))
                    ->helperText(__('pondok.category.slug_helper'))
                    ->maxLength(Category::SLUG_MAX_LENGTH),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query) => $query->orderBy('type')->orderBy('name'))
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount([
                'posts' => fn (Builder $query) => $query->withTrashed(),
                'albums',
            ]))
            ->columns([
                TextColumn::make('name')
                    ->label(__('pondok.category.name'))
                    ->searchable(),
                TextColumn::make('type')
                    ->label(__('pondok.category.type'))
                    ->formatStateUsing(fn (string $state): string => __("pondok.category.types.{$state}")),
                TextColumn::make('slug')
                    ->label(__('pondok.category.slug')),
                TextColumn::make('usage')
                    ->label(__('pondok.category.usage'))
                    ->state(fn (Category $record): int => self::usage($record))
                    ->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('pondok.category.type'))
                    ->options(__('pondok.category.types')),
            ])
            ->emptyStateHeading(__('pondok.category.empty'))
            ->emptyStateIcon(Heroicon::OutlinedTag)
            ->recordActions([
                EditAction::make()
                    ->using(fn (Category $record, array $data, SaveCategory $saveCategory, Action $action): Category => static::saveShowingFormErrors(
                        $action,
                        // Jenis yang dikunci (disabled) tidak terkirim; nilai lama dipakai.
                        fn (): Category => $saveCategory->handle($data + ['type' => $record->type], $record),
                    )),
                DeleteAction::make()
                    ->modalDescription(__('pondok.category.delete_description'))
                    ->hidden(fn (Category $record): bool => self::usage($record) > 0)
                    ->using(function (Category $record, DeleteCategory $deleteCategory, Action $action): bool {
                        try {
                            $deleteCategory->handle($record);
                        } catch (ValidationException $exception) {
                            Notification::make()->title($exception->validator->errors()->first())->danger()->send();
                            $action->halt();
                        }

                        return true;
                    }),
            ]);
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()
            ->using(fn (array $data, SaveCategory $saveCategory, Action $action): Category => static::saveShowingFormErrors(
                $action,
                fn (): Category => $saveCategory->handle($data),
            ));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCategories::route('/'),
        ];
    }

    private static function usage(Category $record): int
    {
        return (int) $record->posts_count + (int) $record->albums_count;
    }
}
