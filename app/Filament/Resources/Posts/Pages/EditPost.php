<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Actions\Konten\SavePost;
use App\Filament\Concerns\ShowsActionErrorsOnPageForm;
use App\Filament\Resources\Posts\PostResource;
use App\Filament\Resources\Posts\Tables\PostsTable;
use App\Models\Post;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPost extends EditRecord
{
    use ShowsActionErrorsOnPageForm;

    protected static string $resource = PostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PostsTable::deleteAction(),
            PostsTable::restoreAction(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $user */
        $user = auth()->user();

        /** @var Post $record */
        return $this->runShowingPageFormErrors(fn (): Post => app(SavePost::class)->handle(PostResource::actionData($data), $user, $record));
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('pondok.post.saved', ['title' => $this->getRecord()->title]);
    }

    protected function afterSave(): void
    {
        // Sampul yang baru disimpan atau dihapus tampil sebagai pratinjau; field unggah dikosongkan.
        $this->data['cover'] = [];
        $this->data['remove_cover'] = false;
    }
}
