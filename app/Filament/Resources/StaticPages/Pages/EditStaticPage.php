<?php

namespace App\Filament\Resources\StaticPages\Pages;

use App\Actions\Konten\UpdateStaticPage;
use App\Filament\Concerns\ShowsActionErrorsOnPageForm;
use App\Filament\Resources\StaticPages\StaticPageResource;
use App\Models\StaticPage;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditStaticPage extends EditRecord
{
    use ShowsActionErrorsOnPageForm;

    protected static string $resource = StaticPageResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var StaticPage $record */
        return $this->runShowingPageFormErrors(fn (): StaticPage => app(UpdateStaticPage::class)->handle($record, $data));
    }
}
