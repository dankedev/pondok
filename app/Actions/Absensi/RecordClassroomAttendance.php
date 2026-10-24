<?php

namespace App\Actions\Absensi;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Mencatat absensi harian satu rombel (F025). Idempoten: sesi (rombel + tanggal) dibuat sekali, lalu
 * setiap simpan memperbarui status santri roster. Santri yang tidak dikirim mempertahankan status
 * tersimpan (atau `hadir`); santri yang terkunci izin dari Perizinan selalu `izin`. Baris santri di luar
 * roster (pindah rombel, lulus) tidak disentuh.
 */
class RecordClassroomAttendance
{
    public function __construct(private BuildAttendanceSheet $buildSheet) {}

    /**
     * @param  array<int, string>  $statuses  student_id => status
     */
    public function handle(User $actor, Classroom $classroom, CarbonInterface|string $date, array $statuses = []): AttendanceSession
    {
        $date = $this->parseDate($date);

        $this->authorize($actor, $classroom, $date);
        $this->validateDate($classroom, $date);

        $sheet = $this->buildSheet->handle($classroom, $date);
        $this->validateStatuses($sheet, $statuses);

        try {
            return $this->save($actor, $classroom, $date, $statuses);
        } catch (UniqueConstraintViolationException) {
            // Pencatat lain baru saja membuat sesi yang sama; ulangi sekali dan pakai sesi itu.
            return $this->save($actor, $classroom, $date, $statuses);
        }
    }

    /**
     * @param  array<int, string>  $statuses
     */
    private function save(User $actor, Classroom $classroom, CarbonImmutable $date, array $statuses): AttendanceSession
    {
        return DB::transaction(function () use ($actor, $classroom, $date, $statuses): AttendanceSession {
            $session = AttendanceSession::withoutGlobalScopes()->firstOrCreate([
                'classroom_id' => $classroom->id,
                'held_on' => $date->toDateString(),
            ]);

            $session->forceFill(['recorded_by' => $actor->id])->save();

            // Lembar dibangun ulang di dalam transaksi agar status tersimpan dan kunci izin terbaru.
            foreach ($this->buildSheet->handle($classroom, $date) as $row) {
                $studentId = $row['student']->id;

                $status = $row['locked']
                    ? AttendanceStatus::Excused
                    : (isset($statuses[$studentId]) ? AttendanceStatus::from($statuses[$studentId]) : $row['status']);

                AttendanceRecord::query()->updateOrCreate(
                    ['attendance_session_id' => $session->id, 'student_id' => $studentId],
                    ['status' => $status],
                );
            }

            return $session;
        });
    }

    private function parseDate(CarbonInterface|string $date): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($date instanceof CarbonInterface ? $date->format('Y-m-d') : $date, config('app.timezone'))->startOfDay();
        } catch (Throwable) {
            $this->fail('held_on', 'invalid_date');
        }
    }

    private function authorize(User $actor, Classroom $classroom, CarbonImmutable $date): void
    {
        $exists = AttendanceSession::withoutGlobalScopes()
            ->where('classroom_id', $classroom->id)
            ->where('held_on', $date->toDateString())
            ->exists();

        $allowed = $actor->can($exists ? 'update:attendance-session' : 'create:attendance-session')
            && AttendanceSession::visibleClassrooms($actor)->whereKey($classroom->id)->exists();

        if (! $allowed) {
            throw new AuthorizationException(__('pondok.attendance.errors.forbidden'));
        }
    }

    private function validateDate(Classroom $classroom, CarbonImmutable $date): void
    {
        if ($date->gt(CarbonImmutable::today(config('app.timezone')))) {
            $this->fail('held_on', 'future_date');
        }

        $year = $classroom->academicYear;

        $day = $date->toDateString();

        if ($year === null || $day < $year->starts_on->toDateString() || $day > $year->ends_on->toDateString()) {
            $this->fail('held_on', 'outside_academic_year', [
                'start' => $year?->starts_on->translatedFormat('j M Y'),
                'end' => $year?->ends_on->translatedFormat('j M Y'),
            ]);
        }
    }

    /**
     * @param  Collection<int, array{student: Student, status: AttendanceStatus, leave: mixed, locked: bool}>  $sheet
     * @param  array<int, string>  $statuses
     */
    private function validateStatuses(Collection $sheet, array $statuses): void
    {
        $roster = $sheet->map(fn (array $row): int => $row['student']->id)->all();

        if (array_diff(array_map('intval', array_keys($statuses)), $roster) !== []) {
            $this->fail('statuses', 'student_not_in_roster');
        }

        foreach ($statuses as $status) {
            if (! is_string($status) || AttendanceStatus::tryFrom($status) === null) {
                $this->fail('statuses', 'invalid_status');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    private function fail(string $field, string $reason, array $replace = []): never
    {
        throw ValidationException::withMessages([
            $field => __("pondok.attendance.errors.{$reason}", $replace),
        ]);
    }
}
