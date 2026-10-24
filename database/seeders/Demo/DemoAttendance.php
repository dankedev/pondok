<?php

namespace Database\Seeders\Demo;

use App\Actions\Absensi\BuildAttendanceSheet;
use App\Actions\Absensi\RecordClassroomAttendance;
use App\Enums\AttendanceStatus;
use App\Models\AttendanceSession;
use App\Models\Classroom;
use Carbon\CarbonImmutable;

/**
 * Absensi harian beberapa hari belajar terakhir (F025) untuk rombel 7A dan 8A MTW/SMP, dicatat lewat
 * `RecordClassroomAttendance` oleh akun admin-sekolah demo (cakupan MTW/SMP). Jumat libur. Santri yang
 * sedang izin keluar dari demo Perizinan otomatis tercatat Izin. Hanya dibuat bila belum ada sesi (idempotent).
 */
final class DemoAttendance
{
    private const CLASSROOMS = ['7A', '8A'];

    private const SCHOOL_DAYS = 5;

    public function __construct(private readonly DemoContext $context) {}

    public function run(): void
    {
        if (AttendanceSession::withoutGlobalScopes()->exists()) {
            return;
        }

        $actor = $this->context->user('admin-sekolah');

        foreach (self::CLASSROOMS as $name) {
            $classroom = Classroom::query()
                ->where('academic_year_id', $this->context->activeYear->id)
                ->where('unit_id', $this->context->unit('SLTP')->id)
                ->where('name', $name)
                ->first();

            if (! $classroom instanceof Classroom) {
                $this->context->skip('absensi', "rombel {$name} tidak ditemukan");

                continue;
            }

            foreach ($this->schoolDays() as $index => $day) {
                app(RecordClassroomAttendance::class)->handle($actor, $classroom, $day, $this->statuses($classroom, $day, $index));
            }
        }
    }

    /**
     * Hari belajar terakhir (tanpa Jumat) di dalam tahun ajaran aktif, terlama lebih dulu.
     *
     * @return list<CarbonImmutable>
     */
    private function schoolDays(): array
    {
        $startsOn = $this->context->activeYear->starts_on->toDateString();
        $days = [];

        for ($ago = 0; count($days) < self::SCHOOL_DAYS && $ago < 14; $ago++) {
            $day = $this->context->calendar->daysAgo($ago);

            if (! $day->isFriday() && $day->toDateString() >= $startsOn) {
                $days[] = $day;
            }
        }

        return array_reverse($days);
    }

    /**
     * Sebagian besar hadir; tiap hari satu santri sakit, dan selang sehari satu santri alpa.
     *
     * @return array<int, string>
     */
    private function statuses(Classroom $classroom, CarbonImmutable $day, int $index): array
    {
        $ids = app(BuildAttendanceSheet::class)->handle($classroom, $day)
            ->map(fn (array $row): int => $row['student']->id)
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $statuses = [$ids[($index * 3) % $ids->count()] => AttendanceStatus::Sick->value];

        if ($index % 2 === 1) {
            $statuses[$ids[($index * 3 + 1) % $ids->count()]] ??= AttendanceStatus::Absent->value;
        }

        return $statuses;
    }
}
