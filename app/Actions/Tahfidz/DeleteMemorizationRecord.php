<?php

namespace App\Actions\Tahfidz;

use App\Actions\Tahfidz\Concerns\GuardsTahfidz;
use App\Models\MemorizationRecord;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Menghapus setoran yang salah catat (koreksi = hapus lalu catat ulang), lalu menghitung ulang capaian juz.
 */
class DeleteMemorizationRecord
{
    use GuardsTahfidz;

    public function __construct(private RefreshJuzProgress $refresh) {}

    public function handle(User $actor, MemorizationRecord $record): void
    {
        $this->authorize($actor, 'delete:memorization-record');
        $this->authorizeVisible($actor, $record->isVisibleTo($actor));

        DB::transaction(function () use ($record): void {
            $record->delete();

            $this->refresh->handle(Student::withoutUnitScope()->withTrashed()->findOrFail($record->student_id));
        });
    }
}
