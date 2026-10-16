<?php

namespace App\Filament\Resources\Posts\Schemas;

use App\Actions\Konten\StorePublicImage;
use App\Models\Category;
use App\Models\Post;
use App\Models\Unit;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\ImageEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PostForm
{
    /** Toolbar isi berita dan halaman statis; tanpa lampiran berkas. */
    public const TOOLBAR = [
        ['bold', 'italic', 'underline', 'strike', 'link'],
        ['h2', 'h3'],
        ['bulletList', 'orderedList', 'blockquote'],
        ['undo', 'redo'],
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make(__('pondok.post.section_content'))
                    ->columnSpan(['default' => 3, 'lg' => 2])
                    ->columns(2)
                    ->schema([
                        Select::make('type')
                            ->label(__('pondok.post.type'))
                            ->options(__('pondok.post.types'))
                            ->default(Post::TYPE_NEWS)
                            ->required(),
                        TextInput::make('title')
                            ->label(__('pondok.post.title'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('slug')
                            ->label(__('pondok.post.slug'))
                            ->helperText(__('pondok.post.slug_helper'))
                            ->maxLength(Post::SLUG_MAX_LENGTH)
                            ->columnSpanFull(),
                        Textarea::make('excerpt')
                            ->label(__('pondok.post.excerpt'))
                            ->helperText(__('pondok.post.excerpt_helper'))
                            ->maxLength(255)
                            ->rows(2)
                            ->columnSpanFull(),
                        RichEditor::make('body')
                            ->label(__('pondok.post.body'))
                            ->toolbarButtons(self::TOOLBAR)
                            ->required()
                            ->columnSpanFull(),
                    ]),
                Section::make(__('pondok.post.section_publication'))
                    ->columnSpan(['default' => 3, 'lg' => 1])
                    ->schema([
                        ToggleButtons::make('status')
                            ->label(__('pondok.post.status'))
                            ->options([
                                Post::STATUS_DRAFT => __('pondok.post.statuses.draft'),
                                Post::STATUS_PUBLISHED => __('pondok.post.statuses.terbit'),
                            ])
                            ->colors(Post::STATUS_COLORS)
                            ->default(Post::STATUS_DRAFT)
                            ->inline()
                            ->required(),
                        DateTimePicker::make('published_at')
                            ->label(__('pondok.post.published_at'))
                            ->helperText(__('pondok.post.published_at_helper'))
                            ->seconds(false),
                        Select::make('category_id')
                            ->label(__('pondok.post.category'))
                            ->options(fn (): array => Category::query()->ofType(Category::TYPE_POST)->orderBy('name')->pluck('name', 'id')->all()),
                        Select::make('unit_id')
                            ->label(__('pondok.post.unit'))
                            ->placeholder(__('pondok.post.unit_placeholder'))
                            ->options(fn (): array => Unit::query()->orderBy('sort_order')->pluck('name', 'id')->all()),
                    ]),
                Section::make(__('pondok.post.section_media'))
                    ->columnSpan(['default' => 3, 'lg' => 2])
                    ->columns(2)
                    ->schema([
                        ImageEntry::make('current_cover')
                            ->label(__('pondok.post.current_cover'))
                            ->disk(StorePublicImage::DISK)
                            ->state(fn (?Post $record): ?string => $record?->cover_path)
                            ->imageHeight(120)
                            ->visible(fn (?Post $record): bool => $record?->cover_path !== null),
                        Toggle::make('remove_cover')
                            ->label(__('pondok.post.remove_cover'))
                            ->visible(fn (?Post $record): bool => $record?->cover_path !== null),
                        FileUpload::make('cover')
                            ->label(__('pondok.post.cover'))
                            ->helperText(__('pondok.post.cover_helper'))
                            ->image()
                            ->storeFiles(false)
                            ->acceptedFileTypes(StorePublicImage::MIME_TYPES)
                            ->maxSize(StorePublicImage::MAX_KILOBYTES)
                            ->imageResizeTargetWidth('1600')
                            ->imageResizeMode('contain')
                            ->imageResizeUpscale(false)
                            ->columnSpanFull(),
                        TextInput::make('youtube_url')
                            ->label(__('pondok.post.youtube_url'))
                            ->helperText(__('pondok.post.youtube_url_helper'))
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
