<?php

namespace App\Filament\Resources\DocumentTypes\Pages;

use App\Filament\Resources\DocumentTypes\DocumentTypeResource;
use Filament\Actions\Action;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Resources\Pages\ManageRecords;

class ManageDocumentTypes extends ManageRecords
{
    protected static string $resource = DocumentTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DocumentTypeResource::createAction(),
        ];
    }

    /**
     * Tabel ini tidak punya aksi massal. Filament mencari aksi baris dengan nama
     * sama bila aksi massal tidak ada; konteks massal di sini ditolak agar hapus
     * per baris (dengan penjaga jenis terpakai) tidak bisa dipanggil sebagai aksi massal.
     *
     * @param  array<string, mixed>  $action
     * @param  array<Action>  $parentActions
     */
    protected function resolveTableAction(array $action, array $parentActions): Action
    {
        if (($action['context']['bulk'] ?? false) && ! count($parentActions) && ! $this->getTable()->hasBulkAction($action['name'])) {
            throw new ActionNotResolvableException("Bulk action [{$action['name']}] not found on table.");
        }

        return parent::resolveTableAction($action, $parentActions);
    }
}
