<?php

namespace App\Filament\Resources\StaticPages\Pages;

use App\Actions\Konten\CreateStaticPage as CreateStaticPageAction;
use App\Filament\Concerns\ShowsActionErrorsOnPageForm;
use App\Filament\Resources\StaticPages\StaticPageResource;
use App\Models\StaticPage;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateStaticPage extends CreateRecord
{
    use ShowsActionErrorsOnPageForm;

    protected static string $resource = StaticPageResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->runShowingPageFormErrors(fn (): StaticPage => app(CreateStaticPageAction::class)->handle($data));
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('pondok.static_page.created', ['title' => $this->getRecord()->title]);
    }

    protected function getRedirectUrl(): string
    {
        return StaticPageResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
