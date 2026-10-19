<?php

namespace App\Filament\Resources\Albums\RelationManagers;

use App\Actions\Konten\AddAlbumPhotos;
use App\Actions\Konten\DeletePhoto;
use App\Actions\Konten\StorePublicImage;
use App\Actions\Konten\UpdatePhoto;
use App\Filament\Concerns\ShowsActionErrorsOnForm;
use App\Filament\Resources\Albums\AlbumResource;
use App\Models\Album;
use App\Models\Photo;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\ImageEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

/**
 * Foto album. Unggah lewat `AddAlbumPhotos`, ubah keterangan, urutan, atau gambar lewat
 * `UpdatePhoto`, hapus (satu atau beberapa) lewat `DeletePhoto`. Mengikuti izin ubah album.
 */
class PhotosRelationManager extends RelationManager
{
    use ShowsActionErrorsOnForm;

    protected static string $relationship = 'photos';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('pondok.photo.plural');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                ImageEntry::make('path')
                    ->label(__('pondok.photo.current_image'))
                    ->disk(StorePublicImage::DISK)
                    ->imageHeight(160),
                FileUpload::make('image')
                    ->label(__('pondok.photo.replace_image'))
                    ->helperText(__('pondok.photo.replace_image_helper'))
                    ->image()
                    ->storeFiles(false)
                    ->acceptedFileTypes(StorePublicImage::MIME_TYPES)
                    ->maxSize(StorePublicImage::MAX_KILOBYTES)
                    ->imageResizeTargetWidth('1600')
                    ->imageResizeMode('contain')
                    ->imageResizeUpscale(false),
                TextInput::make('caption')
                    ->label(__('pondok.photo.caption'))
                    ->maxLength(AddAlbumPhotos::CAPTION_MAX_LENGTH),
                TextInput::make('sort_order')
                    ->label(__('pondok.photo.sort_order'))
                    ->integer()
                    ->minValue(0)
                    ->maxValue(Photo::SORT_ORDER_MAX)
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('caption')
            ->defaultSort('sort_order')
            ->columns([
                ImageColumn::make('path')
                    ->label(__('pondok.photo.image'))
                    ->disk(StorePublicImage::DISK)
                    ->square()
                    ->imageSize(64),
                TextColumn::make('caption')
                    ->label(__('pondok.photo.caption'))
                    ->placeholder('-')
                    ->wrap(),
                TextColumn::make('sort_order')
                    ->label(__('pondok.photo.sort_order'))
                    ->alignEnd()
                    ->sortable(),
            ])
            ->emptyStateHeading(__('pondok.photo.empty'))
            ->emptyStateIcon(Heroicon::OutlinedPhoto)
            ->headerActions([
                $this->uploadAction(),
            ])
            ->recordActions([
                EditAction::make()
                    ->authorize(fn (): bool => $this->canEditAlbum())
                    ->using(fn (Photo $record, array $data, UpdatePhoto $updatePhoto, Action $action): Photo => static::saveShowingFormErrors(
                        $action,
                        fn (): Photo => $updatePhoto->handle($record, $data),
                    )),
                DeleteAction::make()
                    ->authorize(fn (): bool => $this->canEditAlbum())
                    ->modalDescription(__('pondok.photo.delete_description'))
                    ->using(fn (Photo $record, DeletePhoto $deletePhoto): bool => tap(true, fn () => $deletePhoto->handle($record))),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorize(fn (): bool => $this->canEditAlbum())
                        ->modalDescription(__('pondok.photo.delete_description'))
                        ->using(fn (Collection $records, DeletePhoto $deletePhoto) => $records->each(fn (Photo $photo) => $deletePhoto->handle($photo))),
                ]),
            ]);
    }

    private function uploadAction(): Action
    {
        return Action::make('upload')
            ->label(__('pondok.photo.upload'))
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('primary')
            ->authorize(fn (): bool => $this->canEditAlbum())
            ->modalHeading(__('pondok.photo.upload'))
            ->modalSubmitActionLabel(__('pondok.photo.upload'))
            ->schema([
                FileUpload::make('photos')
                    ->label(__('pondok.photo.photos'))
                    ->helperText(__('pondok.photo.photos_helper'))
                    ->image()
                    ->multiple()
                    ->storeFiles(false)
                    ->maxFiles(AddAlbumPhotos::MAX_FILES_PER_UPLOAD)
                    ->acceptedFileTypes(StorePublicImage::MIME_TYPES)
                    ->maxSize(StorePublicImage::MAX_KILOBYTES)
                    ->imageResizeTargetWidth('1600')
                    ->imageResizeMode('contain')
                    ->imageResizeUpscale(false)
                    ->required(),
                TextInput::make('caption')
                    ->label(__('pondok.photo.caption'))
                    ->helperText(__('pondok.photo.caption_helper'))
                    ->maxLength(AddAlbumPhotos::CAPTION_MAX_LENGTH),
            ])
            ->action(function (array $data, AddAlbumPhotos $addPhotos, Action $action): void {
                $files = array_values(array_filter((array) ($data['photos'] ?? []), fn (mixed $file): bool => $file instanceof UploadedFile));

                $photos = static::runShowingFormErrors($action, fn () => $addPhotos->handle($this->album(), $files, $data['caption'] ?? null));

                Notification::make()
                    ->title(__('pondok.photo.uploaded', ['count' => $photos->count()]))
                    ->success()
                    ->send();
            });
    }

    private function album(): Album
    {
        /** @var Album */
        return $this->getOwnerRecord();
    }

    private function canEditAlbum(): bool
    {
        return AlbumResource::canEdit($this->album());
    }
}
