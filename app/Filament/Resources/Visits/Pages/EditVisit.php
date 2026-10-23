<?php

namespace App\Filament\Resources\Visits\Pages;

use App\Actions\Perizinan\UpdateVisit;
use App\Filament\Concerns\ShowsActionErrorsOnPageForm;
use App\Filament\Resources\Visits\VisitResource;
use App\Models\Visit;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditVisit extends EditRecord
{
    use ShowsActionErrorsOnPageForm;

    protected static string $resource = VisitResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Visit $record */
        return $this->runShowingPageFormErrors(fn (): Visit => app(UpdateVisit::class)->handle($record, VisitResource::user(), $data));
    }
}
