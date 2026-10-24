<?php

namespace App\Filament\Resources\ViolationTypes\Pages;

use App\Actions\Kedisiplinan\SaveViolationType;
use App\Filament\Concerns\ShowsActionErrorsOnPageForm;
use App\Filament\Resources\ViolationTypes\ViolationTypeResource;
use App\Models\ViolationType;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditViolationType extends EditRecord
{
    use ShowsActionErrorsOnPageForm;

    protected static string $resource = ViolationTypeResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var ViolationType $record */
        return $this->runShowingPageFormErrors(fn (): ViolationType => app(SaveViolationType::class)->handle(ViolationTypeResource::user(), $data, $record));
    }
}
