<?php

namespace App\Actions\Absensi;

use App\Enums\AttendanceStatus;
use App\Enums\Module;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\LeaveRequest;
use App\Models\Scopes\UnitScope;
use App\Models\Student;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Lembar absensi satu rombel pada satu tanggal (F025), dipakai form dan `RecordClassroomAttendance`.
 * Hanya membaca; tanpa pemeriksaan akses (pemanggil yang memeriksa).
 *
 * Roster = santri `aktif` yang terdaftar di rombel itu, urut nama. Tiap baris berisi `student`, `status`
 * (tersimpan, atau `hadir`; `izin` bila terkunci), `leave` (izin yang menyentuh tanggal itu sebagai
 * petunjuk, atau null), dan `locked` (izin mengunci hari itu, F025 D5).
 */
class BuildAttendanceSheet
{
    /**
     * @return Collection<int, array{student: Student, status: AttendanceStatus, leave: ?LeaveRequest, locked: bool}>
     */
    public function handle(Classroom $classroom, CarbonInterface $date): Collection
    {
        $students = Student::withoutUnitScope()
            ->active()
            ->whereIn('id', Enrollment::query()->where('classroom_id', $classroom->id)->select('student_id'))
            ->orderBy('name')
            ->get();

        $stored = AttendanceRecord::query()
            ->whereIn('attendance_session_id', AttendanceSession::withoutGlobalScopes()
                ->where('classroom_id', $classroom->id)
                ->where('held_on', $date->toDateString())
                ->select('id'))
            ->pluck('status', 'student_id');

        [$locking, $touching] = $this->leaves($students->modelKeys(), $date);

        return $students->map(function (Student $student) use ($stored, $locking, $touching): array {
            $locked = $locking->contains($student->id);

            return [
                'student' => $student,
                'status' => $locked ? AttendanceStatus::Excused : ($stored->get($student->id) ?? AttendanceStatus::Present),
                'leave' => $touching->get($student->id),
                'locked' => $locked,
            ];
        })->values();
    }

    /**
     * Santri yang terkunci izin dan izin petunjuk per santri. Tanpa cakupan izin: wali kelas tidak punya
     * permission perizinan. Kosong bila modul Perizinan mati.
     *
     * @param  list<int>  $studentIds
     * @return array{Collection<int, int>, Collection<int, LeaveRequest>}
     */
    private function leaves(array $studentIds, CarbonInterface $date): array
    {
        if ($studentIds === [] || ! Module::Perizinan->isEnabled()) {
            return [collect(), collect()];
        }

        $leaves = fn () => LeaveRequest::withoutGlobalScopes(['visible', UnitScope::class])->whereIn('student_id', $studentIds);

        $locking = $leaves()->lockingDate($date)->pluck('student_id')->map(fn (mixed $id): int => (int) $id);

        $touching = $leaves()->touchingDate($date)
            ->with('leaveType')
            ->orderByDesc('id')
            ->get()
            ->unique('student_id')
            ->keyBy('student_id');

        return [$locking, $touching];
    }
}
