<?php

namespace App\Actions\Psb;

use App\Models\AcademicYear;
use App\Models\AdmissionWave;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Membuat atau mengubah gelombang PSB.
 *
 * Nama unik dalam satu tahun ajaran (unique DB tetap pengaman terakhir).
 * Rentang tanggal (inklusif) tidak boleh beririsan dengan gelombang lain di
 * tahun ajaran yang sama, terbuka maupun tertutup, agar paling banyak satu
 * gelombang menerima pendaftaran pada satu tanggal. Kuota NULL = tanpa batas.
 */
class SaveAdmissionWave
{
    /** Batas kolom `quota` (unsignedInteger). */
    public const MAX_QUOTA = 4294967295;

    /**
     * @param  array{academic_year_id: mixed, name: mixed, opens_on: mixed, closes_on: mixed, quota?: mixed, is_open?: mixed}  $data
     */
    public function handle(array $data, ?AdmissionWave $wave = null): AdmissionWave
    {
        $academicYearId = filled($data['academic_year_id'] ?? null) ? (int) $data['academic_year_id'] : null;

        if ($academicYearId === null || ! AcademicYear::query()->whereKey($academicYearId)->exists()) {
            $this->fail('academic_year_id', 'academic_year_not_found');
        }

        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 100) {
            $this->fail('name', 'name_required');
        }

        $nameTaken = $this->otherWavesOfYear($academicYearId, $wave)->where('name', $name)->exists();

        if ($nameTaken) {
            $this->fail('name', 'wave_name_taken');
        }

        $opensOn = $this->date($data['opens_on'] ?? null) ?? $this->fail('opens_on', 'date_invalid');
        $closesOn = $this->date($data['closes_on'] ?? null) ?? $this->fail('closes_on', 'date_invalid');

        if ($closesOn < $opensOn) {
            $this->fail('closes_on', 'closes_before_opens');
        }

        $quota = $data['quota'] ?? null;

        if (blank($quota)) {
            $quota = null;
        } else {
            $quota = $this->positiveInteger($quota) ?? $this->fail('quota', 'quota_invalid');
        }

        return DB::transaction(function () use ($data, $wave, $academicYearId, $name, $opensOn, $closesOn, $quota): AdmissionWave {
            $overlaps = $this->otherWavesOfYear($academicYearId, $wave)
                ->whereDate('opens_on', '<=', $closesOn)
                ->whereDate('closes_on', '>=', $opensOn)
                ->exists();

            if ($overlaps) {
                $this->fail('opens_on', 'wave_dates_overlap');
            }

            $wave ??= new AdmissionWave;
            $wave->fill([
                'academic_year_id' => $academicYearId,
                'name' => $name,
                'opens_on' => $opensOn,
                'closes_on' => $closesOn,
                'quota' => $quota,
                'is_open' => (bool) ($data['is_open'] ?? true),
            ])->save();

            return $wave;
        });
    }

    /**
     * @return Builder<AdmissionWave>
     */
    private function otherWavesOfYear(int $academicYearId, ?AdmissionWave $wave): Builder
    {
        return AdmissionWave::query()
            ->where('academic_year_id', $academicYearId)
            ->when($wave, fn (Builder $query, AdmissionWave $wave) => $query->whereKeyNot($wave->getKey()));
    }

    /**
     * CarbonInterface atau string tepat `Y-m-d`, dikembalikan sebagai string `Y-m-d`; selain itu null.
     */
    private function date(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->format('Y-m-d');
        }

        if (! is_string($value)) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : null;
    }

    /**
     * Bilangan bulat 1 sampai MAX_QUOTA (int atau string berisi digit saja); selain itu null.
     */
    private function positiveInteger(mixed $value): ?int
    {
        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $value = filter_var(trim($value), FILTER_VALIDATE_INT);
        }

        return is_int($value) && $value >= 1 && $value <= self::MAX_QUOTA ? $value : null;
    }

    private function fail(string $field, string $reason): never
    {
        throw ValidationException::withMessages([
            $field => __("pondok.psb.errors.{$reason}"),
        ]);
    }
}
