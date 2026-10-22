<?php

namespace App\Actions\Tahfidz;

use App\Actions\Tahfidz\Concerns\GuardsTahfidz;
use App\Models\QuranSurah;
use App\Models\TahfidzExam;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Mencatat ujian tahfidz anggota halaqah, lalu menghitung ulang capaian juz.
 * Rentang per jenis: kenaikan juz satu juz, tasmi' 5 juz tepat lima juz, khataman juz 1–30.
 */
class RecordTahfidzExam
{
    use GuardsTahfidz;

    public function __construct(private RefreshJuzProgress $refresh) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, array $data): TahfidzExam
    {
        $this->authorize($actor, 'create:tahfidz-exam');
        $halaqah = $this->visibleActiveHalaqah($actor, $data['halaqah_id'] ?? null);

        $valid = $this->validate($data, [
            'kind' => ['required', Rule::in(TahfidzExam::KINDS)],
            'juz_from' => ['required', 'integer', 'between:1,'.QuranSurah::LAST_JUZ],
            'juz_to' => ['required', 'integer', 'between:1,'.QuranSurah::LAST_JUZ],
            'exam_on' => ['required', 'date', 'before_or_equal:today'],
            'score' => ['required', 'integer', 'between:0,100'],
            'result' => ['required', Rule::in(TahfidzExam::RESULTS)],
            'certificate_no' => ['nullable', 'string', 'max:255', Rule::unique('tahfidz_exams', 'certificate_no')],
        ]);

        $this->guardRange($valid['kind'], (int) $valid['juz_from'], (int) $valid['juz_to']);

        if (filled($valid['certificate_no'] ?? null) && $valid['result'] !== TahfidzExam::RESULT_PASSED) {
            $this->fail('certificate_no', 'certificate_needs_pass');
        }

        $examOn = CarbonImmutable::parse($valid['exam_on'])->toDateString();
        $student = $this->memberOn($halaqah, $data['student_id'] ?? null, $examOn);
        $examinerId = $this->activeStaffId($actor, $data['examiner_staff_id'] ?? null, 'examiner_staff_id');

        return DB::transaction(function () use ($valid, $halaqah, $student, $examOn, $examinerId): TahfidzExam {
            $exam = new TahfidzExam;
            $exam->forceFill([
                ...$valid,
                'student_id' => $student->id,
                'halaqah_id' => $halaqah->id,
                'exam_on' => $examOn,
                'examiner_staff_id' => $examinerId,
                'certificate_no' => filled($valid['certificate_no'] ?? null) ? trim($valid['certificate_no']) : null,
            ])->save();

            $this->refresh->handle($student);

            return $exam;
        });
    }

    private function guardRange(string $kind, int $from, int $to): void
    {
        if ($kind === 'khataman' && $from !== 1) {
            $this->fail('juz_from', 'khataman_range');
        }

        $valid = match ($kind) {
            'kenaikan_juz' => $to === $from,
            'tasmi_5_juz' => $to - $from === 4,
            'khataman' => $to === QuranSurah::LAST_JUZ,
        };

        if (! $valid) {
            $this->fail('juz_to', "{$kind}_range");
        }
    }
}
