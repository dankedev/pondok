<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Actions\Konten\SavePost;
use App\Filament\Concerns\ShowsActionErrorsOnPageForm;
use App\Filament\Resources\Posts\PostResource;
use App\Models\Post;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePost extends CreateRecord
{
    use ShowsActionErrorsOnPageForm;

    protected static string $resource = PostResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $user */
        $user = auth()->user();

        return $this->runShowingPageFormErrors(fn (): Post => app(SavePost::class)->handle(PostResource::actionData($data), $user));
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('pondok.post.saved', ['title' => $this->getRecord()->title]);
    }
}
