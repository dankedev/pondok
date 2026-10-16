<?php

namespace App\Filament\Resources\StaticPages;

use App\Actions\Konten\DeleteStaticPage;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Resources\Posts\Schemas\PostForm;
use App\Filament\Resources\StaticPages\Pages\CreateStaticPage;
use App\Filament\Resources\StaticPages\Pages\EditStaticPage;
use App\Filament\Resources\StaticPages\Pages\ListStaticPages;
use App\Models\StaticPage;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Halaman statis portal (F016, F037). Buat, ubah, dan hapus lewat Action
 * (`CreateStaticPage`, `UpdateStaticPage`, `DeleteStaticPage`). Halaman sistem
 * tidak punya aksi hapus (disembunyikan eksplisit karena super-admin melewati
 * policy) dan slug-nya terkunci. Tanpa aksi massal.
 */
class StaticPageResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = StaticPage::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocument;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 4;

    public static function getModule(): string
    {
        return 'konten';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.static_page.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.static_page.plural');
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
                            ->label(__('pondok.static_page.title'))
                            ->required()
                            ->maxLength(StaticPage::TITLE_MAX_LENGTH),
                        TextInput::make('slug')
                            ->label(__('pondok.static_page.slug'))
                            ->helperText(fn (?StaticPage $record): string => $record?->isSystem()
                                ? __('pondok.static_page.slug_helper_locked')
                                : __('pondok.static_page.slug_helper_create'))
                            ->maxLength(StaticPage::SLUG_MAX_LENGTH)
                            ->disabled(fn (?StaticPage $record): bool => (bool) $record?->isSystem())
                            ->dehydrated(fn (?StaticPage $record): bool => ! $record?->isSystem()),
                        ToggleButtons::make('status')
                            ->label(__('pondok.static_page.status'))
                            ->options(self::statusOptions())
                            ->colors(StaticPage::STATUS_COLORS)
                            ->default(StaticPage::STATUS_DRAFT)
                            ->inline()
                            ->required(),
                        RichEditor::make('body')
                            ->label(__('pondok.static_page.body'))
                            ->toolbarButtons(PostForm::TOOLBAR)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->paginated(false)
            ->columns([
                TextColumn::make('title')
                    ->label(__('pondok.static_page.title'))
                    ->searchable(),
                TextColumn::make('slug')
                    ->label(__('pondok.static_page.slug'))
                    ->fontFamily('mono'),
                TextColumn::make('status')
                    ->label(__('pondok.static_page.status'))
                    ->badge()
                    ->color(fn (string $state): string => StaticPage::STATUS_COLORS[$state] ?? 'gray')
                    ->formatStateUsing(fn (string $state): string => self::statusOptions()[$state] ?? $state),
                TextColumn::make('kind')
                    ->label(__('pondok.static_page.kind'))
                    ->state(fn (StaticPage $record): ?string => $record->isSystem() ? __('pondok.static_page.system_badge') : null)
                    ->badge()
                    ->color('gray'),
                TextColumn::make('updated_at')
                    ->label(__('pondok.common.updated_at'))
                    ->dateTime('j M Y, H.i'),
            ])
            ->emptyStateHeading(__('pondok.static_page.empty'))
            ->recordActions([
                EditAction::make(),
                self::deleteAction(),
            ]);
    }

    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->hidden(fn (StaticPage $record): bool => $record->isSystem())
            ->modalHeading(fn (StaticPage $record): string => __('pondok.static_page.delete_heading', ['title' => $record->title]))
            ->modalDescription(__('pondok.static_page.delete_description'))
            ->successNotificationTitle(fn (StaticPage $record): string => __('pondok.static_page.deleted', ['title' => $record->title]))
            ->using(fn (StaticPage $record, DeleteStaticPage $deletePage): bool => tap(true, fn () => $deletePage->handle($record)));
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            StaticPage::STATUS_DRAFT => __('pondok.static_page.statuses.draft'),
            StaticPage::STATUS_PUBLISHED => __('pondok.static_page.statuses.terbit'),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaticPages::route('/'),
            'create' => CreateStaticPage::route('/create'),
            'edit' => EditStaticPage::route('/{record}/edit'),
        ];
    }
}
