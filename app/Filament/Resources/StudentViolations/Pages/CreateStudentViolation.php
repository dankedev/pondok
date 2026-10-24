<?php

namespace App\Filament\Resources\StudentViolations\Pages;

use App\Actions\Kedisiplinan\RecordStudentViolation;
use App\Filament\Concerns\ShowsActionErrorsOnPageForm;
use App\Filament\Resources\StudentViolations\StudentViolationResource;
use App\Models\StudentViolation;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateStudentViolation extends CreateRecord
{
    use ShowsActionErrorsOnPageForm;

    protected static string $resource = StudentViolationResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->runShowingPageFormErrors(fn (): StudentViolation => app(RecordStudentViolation::class)->handle(StudentViolationResource::user(), $data));
    }
}
