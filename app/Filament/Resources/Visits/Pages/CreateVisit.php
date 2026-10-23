<?php

namespace App\Filament\Resources\Visits\Pages;

use App\Actions\Perizinan\RecordVisit;
use App\Filament\Concerns\ShowsActionErrorsOnPageForm;
use App\Filament\Resources\Visits\VisitResource;
use App\Models\Visit;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateVisit extends CreateRecord
{
    use ShowsActionErrorsOnPageForm;

    protected static string $resource = VisitResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->runShowingPageFormErrors(fn (): Visit => app(RecordVisit::class)->handle(VisitResource::user(), $data));
    }
}
