<?php

namespace App\Filament\Resources\StudentViolations\Pages;

use App\Actions\Kedisiplinan\RecordStudentViolation;
use App\Filament\Concerns\ShowsActionErrorsOnPageForm;
use App\Filament\Resources\StudentViolations\StudentViolationResource;
use App\Models\StudentViolation;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Ubah pelanggaran yang belum dibatalkan; catatan batal hanya bisa dilihat.
 */
class EditStudentViolation extends EditRecord
{
    use ShowsActionErrorsOnPageForm;

    protected static string $resource = StudentViolationResource::class;

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        /** @var StudentViolation $record */
        $record = $this->getRecord();

        abort_if($record->isCancelled(), 403);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var StudentViolation $record */
        return $this->runShowingPageFormErrors(fn (): StudentViolation => app(RecordStudentViolation::class)->handle(StudentViolationResource::user(), $data, $record));
    }
}
