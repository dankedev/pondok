<?php

namespace App\Filament\Resources\Albums\Pages;

use App\Actions\Konten\SaveAlbum;
use App\Filament\Concerns\ShowsActionErrorsOnPageForm;
use App\Filament\Resources\Albums\AlbumResource;
use App\Models\Album;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditAlbum extends EditRecord
{
    use ShowsActionErrorsOnPageForm;

    protected static string $resource = AlbumResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AlbumResource::deleteAction(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Album $record */
        return $this->runShowingPageFormErrors(fn (): Album => app(SaveAlbum::class)->handle($data, $record));
    }
}
