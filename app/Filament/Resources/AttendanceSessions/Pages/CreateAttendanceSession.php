<?php

namespace App\Filament\Resources\AttendanceSessions\Pages;

use App\Actions\Absensi\RecordClassroomAttendance;
use App\Filament\Concerns\ShowsActionErrorsOnPageForm;
use App\Filament\Resources\AttendanceSessions\AttendanceSessionResource;
use App\Models\AttendanceSession;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Catat absensi rombel. Bila sesi rombel + tanggal sudah ada, lembar memuat status tersimpan dan
 * simpan memperbarui sesi itu (Action idempoten).
 */
class CreateAttendanceSession extends CreateRecord
{
    use ShowsActionErrorsOnPageForm;

    protected static string $resource = AttendanceSessionResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->runShowingPageFormErrors(fn (): AttendanceSession => app(RecordClassroomAttendance::class)->handle(
            AttendanceSessionResource::user(),
            AttendanceSession::visibleClassrooms(AttendanceSessionResource::user())->findOrFail($data['classroom_id']),
            $data['held_on'],
            $data['statuses'] ?? [],
        ));
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return AttendanceSessionResource::savedMessage($this->getRecord());
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
