<?php

namespace App\Actions\Perizinan\Concerns;

use App\Models\Visit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Validasi bersama `RecordVisit` dan `UpdateVisit`. Galat memakai `pondok.visit.errors.*`.
 */
trait ValidatesVisit
{
    use GuardsPerizinan;

    /**
     * Isian kunjungan yang divalidasi (tanpa santri); `guardian_id` harus wali santri `$studentId`.
     *
     * @param  array<string, mixed>  $data
     * @return array{guardian_id: ?int, visitor_name: string, relation: string, started_at: string, ended_at: ?string, items_note: ?string}
     */
    protected function validatedVisit(array $data, int $studentId): array
    {
        $max = Visit::TEXT_MAX_LENGTH;

        $valid = Validator::make($data, [
            'guardian_id' => ['nullable', 'integer'],
            'visitor_name' => ['required', 'string', 'max:255'],
            'relation' => ['required', 'string', 'max:50'],
            'started_at' => ['required', 'date', 'before_or_equal:now'],
            'ended_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            'items_note' => ['nullable', 'string', "max:{$max}"],
        ], [], __('pondok.visit.fields'))->validate();

        $guardianId = filled($valid['guardian_id'] ?? null) ? (int) $valid['guardian_id'] : null;

        $isOwnGuardian = $guardianId === null || DB::table('student_guardian')
            ->where('student_id', $studentId)
            ->where('guardian_id', $guardianId)
            ->exists();

        if (! $isOwnGuardian) {
            $this->fail('guardian_id', 'guardian_mismatch', section: 'visit');
        }

        return [
            'guardian_id' => $guardianId,
            'visitor_name' => $valid['visitor_name'],
            'relation' => $valid['relation'],
            'started_at' => $valid['started_at'],
            'ended_at' => filled($valid['ended_at'] ?? null) ? $valid['ended_at'] : null,
            'items_note' => filled($valid['items_note'] ?? null) ? $valid['items_note'] : null,
        ];
    }
}
