<?php

namespace App\Filament\Resources\Albums\Pages;

use App\Actions\Konten\SaveAlbum;
use App\Filament\Concerns\ShowsActionErrorsOnPageForm;
use App\Filament\Resources\Albums\AlbumResource;
use App\Models\Album;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateAlbum extends CreateRecord
{
    use ShowsActionErrorsOnPageForm;

    protected static string $resource = AlbumResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->runShowingPageFormErrors(fn (): Album => app(SaveAlbum::class)->handle($data));
    }

    protected function getRedirectUrl(): string
    {
        // Setelah album dibuat, langsung unggah foto di halaman ubah.
        return AlbumResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
