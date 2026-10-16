<?php

namespace App\Filament\Resources\StaticPages;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Resources\Posts\Schemas\PostForm;
use App\Filament\Resources\StaticPages\Pages\EditStaticPage;
use App\Filament\Resources\StaticPages\Pages\ListStaticPages;
use App\Models\StaticPage;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Halaman statis tetap (profil, visi-misi, kontak). Hanya diubah lewat
 * `UpdateStaticPage`; tidak ada route buat dan tidak ada aksi hapus.
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

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
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
                            ->maxLength(255),
                        TextInput::make('slug')
                            ->label(__('pondok.static_page.slug'))
                            ->helperText(__('pondok.static_page.slug_helper'))
                            ->disabled()
                            ->dehydrated(false),
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
                    ->label(__('pondok.static_page.title')),
                TextColumn::make('slug')
                    ->label(__('pondok.static_page.slug'))
                    ->fontFamily('mono'),
                TextColumn::make('updated_at')
                    ->label(__('pondok.common.updated_at'))
                    ->dateTime('j M Y, H.i'),
            ])
            ->emptyStateHeading(__('pondok.static_page.empty'))
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaticPages::route('/'),
            'edit' => EditStaticPage::route('/{record}/edit'),
        ];
    }
}
