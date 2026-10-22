<?php

namespace App\Actions\Tahfidz;

use App\Models\MemorizationRecord;
use App\Models\QuranSurah;
use App\Models\Student;
use App\Models\TahfidzExam;
use Illuminate\Support\Facades\DB;

/**
 * Menghitung ulang `student_juz_progress` satu santri dari nol (idempoten).
 *
 * - `lulus_ujian`: juz dalam rentang ujian lulus; `completed_on` = tanggal hafal bila ada,
 *   selain itu tanggal ujian lulus pertama.
 * - `hafal`: gabungan rentang ziyadah (nilai selain `ulang`) menutup seluruh juz; `completed_on` =
 *   tanggal setoran yang menutupnya (urut tanggal lalu id).
 * - `proses`: sebagian juz tersentuh. Juz lain tidak punya baris.
 */
class RefreshJuzProgress
{
    public function handle(Student $student): void
    {
        DB::transaction(function () use ($student): void {
            Student::withoutUnitScope()->withTrashed()->lockForUpdate()->select('id')->findOrFail($student->id);

            $rows = $this->compute($student->id);

            DB::table('student_juz_progress')->where('student_id', $student->id)->delete();

            if ($rows !== []) {
                $now = now();
                DB::table('student_juz_progress')->insert(array_map(
                    fn (array $row): array => [...$row, 'student_id' => $student->id, 'created_at' => $now, 'updated_at' => $now],
                    $rows,
                ));
            }
        });
    }

    /**
     * @return list<array{juz: int, status: string, completed_on: ?string}>
     */
    private function compute(int $studentId): array
    {
        $ranges = [];
        foreach (range(1, QuranSurah::LAST_JUZ) as $juz) {
            $ranges[$juz] = QuranSurah::juzRange($juz);
        }

        /** @var array<int, list<array{0: int, 1: int}>> $covered */
        $covered = [];
        /** @var array<int, string> $hafalOn */
        $hafalOn = [];

        $records = MemorizationRecord::withoutUnitScope()
            ->where('student_id', $studentId)
            ->where('type', 'ziyadah')
            ->where('grade', '!=', 'ulang')
            ->orderBy('recorded_on')
            ->orderBy('id')
            ->toBase()
            ->get(['recorded_on', 'from_surah_id', 'from_ayah', 'to_surah_id', 'to_ayah']);

        foreach ($records as $record) {
            $from = QuranSurah::globalIndex((int) $record->from_surah_id, (int) $record->from_ayah);
            $to = QuranSurah::globalIndex((int) $record->to_surah_id, (int) $record->to_ayah);

            foreach ($ranges as $juz => [$start, $end]) {
                if ($to < $start || $from > $end) {
                    continue;
                }

                $covered[$juz][] = [max($from, $start), min($to, $end)];

                if (! isset($hafalOn[$juz]) && $this->covers($covered[$juz], $start, $end)) {
                    $hafalOn[$juz] = substr((string) $record->recorded_on, 0, 10);
                }
            }
        }

        /** @var array<int, string> $passedOn */
        $passedOn = [];
        $exams = TahfidzExam::withoutUnitScope()
            ->where('student_id', $studentId)
            ->where('result', TahfidzExam::RESULT_PASSED)
            ->orderBy('exam_on')
            ->orderBy('id')
            ->toBase()
            ->get(['exam_on', 'juz_from', 'juz_to']);

        foreach ($exams as $exam) {
            foreach (range((int) $exam->juz_from, (int) $exam->juz_to) as $juz) {
                $passedOn[$juz] ??= substr((string) $exam->exam_on, 0, 10);
            }
        }

        $rows = [];
        foreach (array_keys($ranges) as $juz) {
            $row = match (true) {
                isset($passedOn[$juz]) => ['status' => 'lulus_ujian', 'completed_on' => $hafalOn[$juz] ?? $passedOn[$juz]],
                isset($hafalOn[$juz]) => ['status' => 'hafal', 'completed_on' => $hafalOn[$juz]],
                isset($covered[$juz]) => ['status' => 'proses', 'completed_on' => null],
                default => null,
            };

            if ($row !== null) {
                $rows[] = ['juz' => $juz, ...$row];
            }
        }

        return $rows;
    }

    /**
     * Apakah gabungan interval menutup [start, end] tanpa celah.
     *
     * @param  list<array{0: int, 1: int}>  $intervals
     */
    private function covers(array $intervals, int $start, int $end): bool
    {
        usort($intervals, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $reach = $start - 1;

        foreach ($intervals as [$from, $to]) {
            if ($from > $reach + 1) {
                return false;
            }

            $reach = max($reach, $to);
        }

        return $reach >= $end;
    }
}
