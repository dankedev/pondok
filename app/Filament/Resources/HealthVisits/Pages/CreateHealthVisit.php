<?php

namespace App\Filament\Resources\HealthVisits\Pages;

use App\Actions\Kesehatan\RecordHealthVisit;
use App\Filament\Concerns\ShowsActionErrorsOnPageForm;
use App\Filament\Resources\HealthVisits\HealthVisitResource;
use App\Models\HealthVisit;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateHealthVisit extends CreateRecord
{
    use ShowsActionErrorsOnPageForm;

    protected static string $resource = HealthVisitResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->runShowingPageFormErrors(fn (): HealthVisit => app(RecordHealthVisit::class)->handle(HealthVisitResource::user(), $data));
    }
}
