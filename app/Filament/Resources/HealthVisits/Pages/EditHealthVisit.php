<?php

namespace App\Filament\Resources\HealthVisits\Pages;

use App\Actions\Kesehatan\UpdateHealthVisit;
use App\Filament\Concerns\ShowsActionErrorsOnPageForm;
use App\Filament\Resources\HealthVisits\HealthVisitResource;
use App\Models\HealthVisit;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditHealthVisit extends EditRecord
{
    use ShowsActionErrorsOnPageForm;

    protected static string $resource = HealthVisitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HealthVisitResource::deleteAction(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var HealthVisit $record */
        return $this->runShowingPageFormErrors(fn (): HealthVisit => app(UpdateHealthVisit::class)->handle($record, HealthVisitResource::user(), $data));
    }
}
