<?php

namespace App\Filament\Resources\ViolationTypes\Pages;

use App\Actions\Kedisiplinan\SaveViolationType;
use App\Filament\Concerns\ShowsActionErrorsOnPageForm;
use App\Filament\Resources\ViolationTypes\ViolationTypeResource;
use App\Models\ViolationType;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateViolationType extends CreateRecord
{
    use ShowsActionErrorsOnPageForm;

    protected static string $resource = ViolationTypeResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->runShowingPageFormErrors(fn (): ViolationType => app(SaveViolationType::class)->handle(ViolationTypeResource::user(), $data));
    }
}
