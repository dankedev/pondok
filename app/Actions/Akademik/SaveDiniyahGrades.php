<?php

namespace App\Actions\Akademik;

use App\Models\Classroom;
use App\Models\DiniyahGrade;
use App\Models\DiniyahReportNote;
use App\Models\DiniyahSubject;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Menyimpan nilai mapel diniyah dan catatan wali kelas satu rombel pada satu semester (F027).
 * Atomik: semua isian divalidasi dulu, lalu disimpan dalam satu transaksi. Idempoten; isian kosong
 * (`null` atau string kosong) menghapus baris nilai atau catatan. Roster = santri `aktif` yang
 * terdaftar di rombel; mapel = mapel aktif jenjang rombel. Tahun ajaran rombel tidak harus aktif.
 */
class SaveDiniyahGrades
{
    public const MAX_NOTE_LENGTH = 1000;

    /**
     * @param  array<int, array<int, mixed>>  $scores  student_id => [subject_id => nilai|null]
     * @param  array<int, mixed>  $notes  student_id => catatan|null
     * @return int jumlah nilai dan catatan yang diisi
     */
    public function handle(User $actor, Classroom $classroom, mixed $semester, array $scores, array $notes): int
    {
        $this->authorize($actor, $classroom);

        $semester = filter_var($semester, FILTER_VALIDATE_INT);

        if (! in_array($semester, [1, 2], true)) {
            $this->fail('semester', 'invalid_semester');
        }

        [$set, $clear] = $this->validatedScores($classroom, $scores);
        [$noteSet, $noteClear] = $this->validatedNotes($classroom, $notes);

        return DB::transaction(function () use ($actor, $classroom, $semester, $set, $clear, $noteSet, $noteClear): int {
            $key = ['academic_year_id' => $classroom->academic_year_id, 'semester' => $semester];

            foreach ($set as [$studentId, $subjectId, $score]) {
                DiniyahGrade::query()->updateOrCreate(
                    [...$key, 'student_id' => $studentId, 'diniyah_subject_id' => $subjectId],
                    ['score' => $score, 'recorded_by' => $actor->id],
                );
            }

            foreach ($clear as [$studentId, $subjectId]) {
                DiniyahGrade::query()->where([...$key, 'student_id' => $studentId, 'diniyah_subject_id' => $subjectId])->delete();
            }

            foreach ($noteSet as $studentId => $note) {
                DiniyahReportNote::query()->updateOrCreate(
                    [...$key, 'student_id' => $studentId],
                    ['note' => $note, 'recorded_by' => $actor->id],
                );
            }

            DiniyahReportNote::query()->where($key)->whereIn('student_id', $noteClear)->delete();

            return count($set) + count($noteSet);
        });
    }

    private function authorize(User $actor, Classroom $classroom): void
    {
        if (! $actor->can('input-diniyah-grades') || ! Classroom::visibleTo($actor)->whereKey($classroom->id)->exists()) {
            throw new AuthorizationException(__('pondok.diniyah.errors.forbidden'));
        }
    }

    /**
     * @param  array<int, array<int, mixed>>  $scores
     * @return array{list<array{int, int, int}>, list<array{int, int}>} [diisi, dikosongkan]
     */
    private function validatedScores(Classroom $classroom, array $scores): array
    {
        $roster = $this->rosterIds($classroom);
        $subjects = DiniyahSubject::withoutUnitScope()
            ->active()
            ->where('unit_id', $classroom->unit_id)
            ->pluck('id')
            ->all();

        $set = $clear = [];

        foreach ($scores as $studentId => $cells) {
            if (! in_array((int) $studentId, $roster, true) || ! is_array($cells)) {
                $this->fail('scores', 'student_not_in_classroom');
            }

            foreach ($cells as $subjectId => $value) {
                if (! in_array((int) $subjectId, $subjects, true)) {
                    $this->fail('scores', 'subject_unavailable');
                }

                if ($value === null || $value === '') {
                    $clear[] = [(int) $studentId, (int) $subjectId];

                    continue;
                }

                $set[] = [(int) $studentId, (int) $subjectId, $this->score($value)];
            }
        }

        return [$set, $clear];
    }

    /**
     * @param  array<int, mixed>  $notes
     * @return array{array<int, string>, list<int>} [diisi, dikosongkan]
     */
    private function validatedNotes(Classroom $classroom, array $notes): array
    {
        $roster = $this->rosterIds($classroom);
        $set = $clear = [];

        foreach ($notes as $studentId => $note) {
            if (! in_array((int) $studentId, $roster, true) || ! (is_string($note) || $note === null)) {
                $this->fail('notes', 'student_not_in_classroom');
            }

            if ($note === null || trim($note) === '') {
                $clear[] = (int) $studentId;
            } elseif (mb_strlen(trim($note)) > self::MAX_NOTE_LENGTH) {
                $this->fail('notes', 'note_too_long');
            } else {
                $set[(int) $studentId] = trim($note);
            }
        }

        return [$set, $clear];
    }

    private function score(mixed $value): int
    {
        $score = is_int($value) ? $value : (is_string($value) && preg_match('/^\d{1,3}$/', trim($value)) ? (int) $value : null);

        if ($score === null || $score < 0 || $score > 100) {
            $this->fail('scores', 'invalid_score');
        }

        return $score;
    }

    /**
     * @return list<int>
     */
    private function rosterIds(Classroom $classroom): array
    {
        return $classroom->rosterStudents()
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function fail(string $field, string $reason): never
    {
        throw ValidationException::withMessages([$field => __("pondok.diniyah.errors.{$reason}")]);
    }
}
