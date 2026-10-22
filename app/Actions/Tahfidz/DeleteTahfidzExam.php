<?php

namespace App\Actions\Tahfidz;

use App\Actions\Tahfidz\Concerns\GuardsTahfidz;
use App\Models\Student;
use App\Models\TahfidzExam;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Menghapus ujian yang salah catat, lalu menghitung ulang capaian juz.
 */
class DeleteTahfidzExam
{
    use GuardsTahfidz;

    public function __construct(private RefreshJuzProgress $refresh) {}

    public function handle(User $actor, TahfidzExam $exam): void
    {
        $this->authorize($actor, 'delete:tahfidz-exam');
        $this->authorizeVisible($actor, $exam->isVisibleTo($actor));

        DB::transaction(function () use ($exam): void {
            $exam->delete();

            $this->refresh->handle(Student::withoutUnitScope()->withTrashed()->findOrFail($exam->student_id));
        });
    }
}
