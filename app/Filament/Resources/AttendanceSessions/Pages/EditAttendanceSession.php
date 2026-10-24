<?php

namespace App\Filament\Resources\AttendanceSessions\Pages;

use App\Actions\Absensi\RecordClassroomAttendance;
use App\Filament\Concerns\ShowsActionErrorsOnPageForm;
use App\Filament\Resources\AttendanceSessions\AttendanceSessionResource;
use App\Filament\Resources\AttendanceSessions\Schemas\AttendanceSessionForm;
use App\Models\AttendanceSession;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Ubah status absensi sebuah sesi. Rombel dan tanggal terkunci; tanpa aksi hapus (F025 D8).
 */
class EditAttendanceSession extends EditRecord
{
    use ShowsActionErrorsOnPageForm;

    protected static string $resource = AttendanceSessionResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var AttendanceSession $record */
        $record = $this->getRecord();
        $heldOn = $record->held_on->toDateString();

        return [
            ...$data,
            'held_on' => $heldOn,
            'statuses' => AttendanceSessionForm::statuses(AttendanceSessionForm::sheet($record->classroom_id, $heldOn) ?? collect()),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var AttendanceSession $record */
        return $this->runShowingPageFormErrors(fn (): AttendanceSession => app(RecordClassroomAttendance::class)->handle(
            AttendanceSessionResource::user(),
            $record->classroom,
            $record->held_on,
            $data['statuses'] ?? [],
        ));
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return AttendanceSessionResource::savedMessage($this->getRecord());
    }
}
