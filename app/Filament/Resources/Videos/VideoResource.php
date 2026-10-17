<?php

namespace App\Filament\Resources\Videos;

use App\Actions\Konten\DeleteVideo;
use App\Actions\Konten\SaveVideo;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ShowsActionErrorsOnForm;
use App\Filament\Resources\Videos\Pages\ManageVideos;
use App\Models\Video;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Video YouTube untuk situs (F016). Simpan lewat `SaveVideo`, hapus lewat `DeleteVideo`.
 */
class VideoResource extends Resource
{
    use BelongsToModule, ShowsActionErrorsOnForm;

    protected static ?string $model = Video::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFilm;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 8;

    public static function getModule(): string
    {
        return 'konten';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.video.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.video.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.situs');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('title')
                    ->label(__('pondok.video.title'))
                    ->required()
                    ->maxLength(SaveVideo::TITLE_MAX_LENGTH)
                    ->columnSpanFull(),
                TextInput::make('youtube')
                    ->label(__('pondok.video.youtube'))
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Textarea::make('description')
                    ->label(__('pondok.video.description'))
                    ->maxLength(SaveVideo::DESCRIPTION_MAX_LENGTH)
                    ->rows(3)
                    ->columnSpanFull(),
                Toggle::make('is_featured')
                    ->label(__('pondok.video.is_featured')),
                TextInput::make('sort_order')
                    ->label(__('pondok.video.sort_order'))
                    ->integer()
                    ->minValue(0)
                    ->maxValue(SaveVideo::SORT_ORDER_MAX)
                    ->default(0),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query) => $query->ordered())
            ->columns([
                TextColumn::make('title')
                    ->label(__('pondok.video.title'))
                    ->searchable(),
                TextColumn::make('youtube_id')
                    ->label(__('pondok.video.youtube_id'))
                    ->fontFamily('mono')
                    ->url(fn (Video $record): string => $record->watchUrl())
                    ->openUrlInNewTab(),
                IconColumn::make('is_featured')
                    ->label(__('pondok.video.featured'))
                    ->boolean(),
                TextColumn::make('sort_order')
                    ->label(__('pondok.video.sort_order'))
                    ->alignEnd(),
            ])
            ->emptyStateHeading(__('pondok.video.empty'))
            ->emptyStateIcon(Heroicon::OutlinedFilm)
            ->recordActions([
                EditAction::make()
                    ->fillForm(fn (Video $record): array => [
                        'title' => $record->title,
                        'youtube' => $record->watchUrl(),
                        'description' => $record->description,
                        'is_featured' => $record->is_featured,
                        'sort_order' => $record->sort_order,
                    ])
                    ->using(fn (Video $record, array $data, SaveVideo $saveVideo, Action $action): Video => static::saveShowingFormErrors(
                        $action,
                        fn (): Video => $saveVideo->handle($data, $record),
                    )),
                DeleteAction::make()
                    ->modalDescription(__('pondok.video.delete_description'))
                    ->using(fn (Video $record, DeleteVideo $deleteVideo): bool => tap(true, fn () => $deleteVideo->handle($record))),
            ]);
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()
            ->using(fn (array $data, SaveVideo $saveVideo, Action $action): Video => static::saveShowingFormErrors(
                $action,
                fn (): Video => $saveVideo->handle($data),
            ));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageVideos::route('/'),
        ];
    }
}
