<?php

namespace App\Filament\Resources\Albums;

use App\Actions\Konten\DeleteAlbum;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Resources\Albums\Pages\CreateAlbum;
use App\Filament\Resources\Albums\Pages\EditAlbum;
use App\Filament\Resources\Albums\Pages\ListAlbums;
use App\Filament\Resources\Albums\RelationManagers\PhotosRelationManager;
use App\Models\Album;
use App\Models\Category;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Galeri foto berkategori (F016). Simpan lewat `SaveAlbum`; foto dikelola di
 * `PhotosRelationManager`. Album dihapus permanen beserta fotonya (`DeleteAlbum`).
 */
class AlbumResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Album::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 2;

    public static function getModule(): string
    {
        return 'konten';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.album.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.album.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.konten');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->label(__('pondok.album.title'))
                            ->required()
                            ->maxLength(255),
                        Select::make('category_id')
                            ->label(__('pondok.album.category'))
                            ->options(fn (): array => self::categoryOptions())
                            ->required(),
                        TextInput::make('slug')
                            ->label(__('pondok.album.slug'))
                            ->helperText(__('pondok.album.slug_helper'))
                            ->maxLength(Album::SLUG_MAX_LENGTH),
                        Textarea::make('description')
                            ->label(__('pondok.album.description'))
                            ->maxLength(2000)
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('category')->withCount('photos'))
            ->columns([
                TextColumn::make('title')
                    ->label(__('pondok.album.title'))
                    ->description(fn (Album $record): string => $record->slug)
                    ->searchable(),
                TextColumn::make('category.name')
                    ->label(__('pondok.album.category')),
                TextColumn::make('photos_count')
                    ->label(__('pondok.album.photos_count'))
                    ->alignEnd(),
                TextColumn::make('updated_at')
                    ->label(__('pondok.common.updated_at'))
                    ->date('j M Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('category_id')
                    ->label(__('pondok.album.category'))
                    ->options(fn (): array => self::categoryOptions()),
            ])
            ->emptyStateHeading(__('pondok.album.empty'))
            ->emptyStateIcon(Heroicon::OutlinedPhoto)
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('pondok.album.create'))
                    ->url(fn (): string => static::getUrl('create')),
            ])
            ->recordActions([
                EditAction::make(),
                self::deleteAction(),
            ]);
    }

    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription(__('pondok.album.delete_description'))
            ->using(fn (Album $record, DeleteAlbum $deleteAlbum): bool => tap(true, fn () => $deleteAlbum->handle($record)));
    }

    /**
     * @return array<int, string>
     */
    public static function categoryOptions(): array
    {
        return Category::query()->ofType(Category::TYPE_ALBUM)->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function getRelations(): array
    {
        return [
            PhotosRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAlbums::route('/'),
            'create' => CreateAlbum::route('/create'),
            'edit' => EditAlbum::route('/{record}/edit'),
        ];
    }
}
