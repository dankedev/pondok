<?php

namespace App\Actions\Tahfidz;

use App\Actions\Tahfidz\Concerns\GuardsTahfidz;
use App\Models\MemorizationRecord;
use App\Models\QuranSurah;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Mencatat setoran hafalan santri di halaqah yang terlihat oleh pencatat, lalu menghitung
 * ulang capaian juz. Rentang boleh lintas surah asal maju (indeks global `from` ≤ `to`).
 */
class RecordMemorization
{
    use GuardsTahfidz;

    public function __construct(private RefreshJuzProgress $refresh) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, array $data): MemorizationRecord
    {
        $this->authorize($actor, 'create:memorization-record');
        $halaqah = $this->visibleActiveHalaqah($actor, $data['halaqah_id'] ?? null);

        $valid = $this->validate($data, [
            'recorded_on' => ['required', 'date', 'before_or_equal:today'],
            'session' => ['required', Rule::in(MemorizationRecord::SESSIONS)],
            'type' => ['required', Rule::in(MemorizationRecord::TYPES)],
            'from_surah_id' => ['required', 'integer', 'between:1,'.QuranSurah::LAST_SURAH],
            'from_ayah' => ['required', 'integer', 'min:1'],
            'to_surah_id' => ['required', 'integer', 'between:1,'.QuranSurah::LAST_SURAH],
            'to_ayah' => ['required', 'integer', 'min:1'],
            'pages' => ['required', 'numeric', 'between:0.1,'.MemorizationRecord::MAX_PAGES],
            'grade' => ['required', Rule::in(MemorizationRecord::GRADES)],
            'mistakes' => ['nullable', 'integer', 'between:0,255'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $this->guardRange((int) $valid['from_surah_id'], (int) $valid['from_ayah'], (int) $valid['to_surah_id'], (int) $valid['to_ayah']);

        $recordedOn = CarbonImmutable::parse($valid['recorded_on'])->toDateString();
        $student = $this->memberOn($halaqah, $data['student_id'] ?? null, $recordedOn);
        $listenerId = $this->activeStaffId($actor, $data['listener_staff_id'] ?? null, 'listener_staff_id');

        return DB::transaction(function () use ($actor, $valid, $halaqah, $student, $recordedOn, $listenerId): MemorizationRecord {
            $record = new MemorizationRecord;
            $record->forceFill([
                ...$valid,
                'student_id' => $student->id,
                'halaqah_id' => $halaqah->id,
                'listener_staff_id' => $listenerId,
                'recorded_on' => $recordedOn,
                'pages' => round((float) $valid['pages'], 1),
                'mistakes' => (int) ($valid['mistakes'] ?? 0),
                'recorded_by' => $actor->id,
            ])->save();

            $this->refresh->handle($student);

            return $record;
        });
    }

    private function guardRange(int $fromSurah, int $fromAyah, int $toSurah, int $toAyah): void
    {
        $counts = QuranSurah::ayahCounts();

        if ($fromAyah > $counts[$fromSurah]) {
            $this->fail('from_ayah', 'ayah_out_of_surah', ['max' => $counts[$fromSurah]]);
        }

        if ($toAyah > $counts[$toSurah]) {
            $this->fail('to_ayah', 'ayah_out_of_surah', ['max' => $counts[$toSurah]]);
        }

        if (QuranSurah::globalIndex($fromSurah, $fromAyah) > QuranSurah::globalIndex($toSurah, $toAyah)) {
            $this->fail('to_ayah', 'range_backward');
        }
    }
}
