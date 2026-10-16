<?php

namespace App\Actions\Keuangan;

use App\Models\Applicant;
use App\Models\FeeOverride;
use App\Models\FeeType;
use App\Models\Scholarship;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Membuat atau mengubah keringanan.
 *
 * Pemilik (santri dan/atau pendaftar) hanya ditentukan saat dibuat; saat
 * edit pemilik dan `approved_by` tetap. Santri dicari lewat `Student::query()`
 * sehingga cakupan jenjang user login berlaku; pendaftar juga lewat
 * `Applicant::query()` (cakupan jenjang, tanpa yang terhapus). Mode dan nilai yang
 * disimpan selalu dari masukan, bukan bawaan program beasiswa. Rentang
 * tanggal tidak boleh beririsan dengan keringanan lain untuk pemilik dan
 * jenis biaya yang sama.
 */
class SaveFeeOverride
{
    /**
     * @param  array{student_id?: int|string|null, applicant_id?: int|string|null, fee_type_id: int|string|null, scholarship_id?: int|string|null, mode: mixed, value: mixed, reason: mixed, valid_from: CarbonInterface|string|null, valid_until?: CarbonInterface|string|null}  $data
     */
    public function handle(array $data, User $approver, ?FeeOverride $override = null): FeeOverride
    {
        [$studentId, $applicantId] = $override === null
            ? $this->owner($data)
            : [$override->student_id, $override->applicant_id];

        $feeTypeId = $this->nullableId($data['fee_type_id'] ?? null);

        if ($feeTypeId === null || ! FeeType::query()->whereKey($feeTypeId)->exists()) {
            $this->fail('fee_type_id', 'fee_type_not_found');
        }

        $scholarshipId = $this->scholarshipId($data, $override);

        $mode = $data['mode'] ?? null;

        if (! in_array($mode, FeeOverride::MODES, true)) {
            $this->fail('mode', 'invalid_mode');
        }

        $value = $this->wholeNumber($data['value'] ?? null);

        if ($value === null) {
            $this->fail('value', 'value_invalid');
        }

        if ($mode === FeeOverride::MODE_PERCENT && $value > 100) {
            $this->fail('value', 'percent_range');
        }

        $reason = trim((string) ($data['reason'] ?? ''));

        if ($reason === '' || mb_strlen($reason) > 255) {
            $this->fail('reason', 'reason_invalid');
        }

        $validFrom = $this->date($data['valid_from'] ?? null) ?? $this->fail('valid_from', 'date_invalid');
        $validUntil = blank($data['valid_until'] ?? null)
            ? null
            : ($this->date($data['valid_until']) ?? $this->fail('valid_until', 'date_invalid'));

        if ($validUntil !== null && $validUntil->lt($validFrom)) {
            $this->fail('valid_until', 'until_before_from');
        }

        $attributes = [
            'fee_type_id' => $feeTypeId,
            'scholarship_id' => $scholarshipId,
            'mode' => $mode,
            'value' => $value,
            'reason' => $reason,
            'valid_from' => $validFrom->toDateString(),
            'valid_until' => $validUntil?->toDateString(),
        ];

        return DB::transaction(function () use ($attributes, $studentId, $applicantId, $validFrom, $validUntil, $approver, $override): FeeOverride {
            if ($override === null && $applicantId !== null) {
                $this->ensureApplicantNotConverted($applicantId);
            }

            $overlapping = FeeOverride::query()
                ->when($override, fn ($query, FeeOverride $override) => $query->whereKeyNot($override->getKey()))
                ->when(
                    $studentId !== null,
                    fn ($query) => $query->where('student_id', $studentId),
                    fn ($query) => $query->where('applicant_id', $applicantId),
                )
                ->where('fee_type_id', $attributes['fee_type_id'])
                // Tanpa tanggal akhir = tak berujung.
                ->activeBetween($validFrom, $validUntil ?? CarbonImmutable::create(9999, 12, 31))
                ->lockForUpdate()
                ->exists();

            if ($overlapping) {
                $this->fail('valid_from', 'overlapping_override');
            }

            if ($override === null) {
                $override = new FeeOverride([
                    'student_id' => $studentId,
                    'applicant_id' => $applicantId,
                    'approved_by' => $approver->id,
                ]);
            }

            $override->fill($attributes)->save();

            return $override;
        });
    }

    /**
     * Pemilik saat create: santri aktif dalam cakupan user login, dan/atau id pendaftar.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function owner(array $data): array
    {
        $studentId = $this->nullableId($data['student_id'] ?? null);
        $applicantId = $this->nullableId($data['applicant_id'] ?? null);

        if ($studentId === null && $applicantId === null) {
            $this->fail('student_id', 'owner_required');
        }

        if ($studentId !== null) {
            $student = Student::query()->find($studentId) ?? $this->fail('student_id', 'student_not_found');

            if (! $student->isActive()) {
                $this->fail('student_id', 'student_not_active');
            }
        }

        if ($applicantId !== null && ! Applicant::query()->whereKey($applicantId)->exists()) {
            $this->fail('applicant_id', 'applicant_not_found');
        }

        return [$studentId, $applicantId];
    }

    /**
     * Mengunci baris pendaftar (kunci yang sama dengan daftar ulang F012) lalu
     * menolak pendaftar yang sudah menjadi santri: keringanan selalu dibuat
     * sebelum konversi (lalu ikut dipindahkan) atau ditolak sesudahnya.
     */
    private function ensureApplicantNotConverted(int $applicantId): void
    {
        $applicant = Applicant::withoutGlobalScopes()->withTrashed()->lockForUpdate()->find($applicantId);
        $student = $applicant?->convertedStudent();

        if ($student !== null) {
            throw ValidationException::withMessages([
                'applicant_id' => __('pondok.keuangan.errors.applicant_converted', ['nis' => $student->nis]),
            ]);
        }
    }

    /**
     * Program harus ada dan aktif; saat edit, program yang tidak berubah boleh sudah nonaktif.
     */
    private function scholarshipId(array $data, ?FeeOverride $override): ?int
    {
        $scholarshipId = $this->nullableId($data['scholarship_id'] ?? null);

        if ($scholarshipId === null) {
            return null;
        }

        $scholarship = Scholarship::query()->find($scholarshipId) ?? $this->fail('scholarship_id', 'scholarship_not_found');

        if (! $scholarship->is_active && $scholarshipId !== $override?->scholarship_id) {
            $this->fail('scholarship_id', 'scholarship_inactive');
        }

        return $scholarshipId;
    }

    /**
     * CarbonInterface atau string tepat `Y-m-d`; selain itu null.
     */
    private function date(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value)->startOfDay();
        }

        if (! is_string($value)) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value
            ? CarbonImmutable::instance($parsed)
            : null;
    }

    /**
     * Bilangan bulat >= 0 (int atau string berisi digit saja); selain itu null.
     */
    private function wholeNumber(mixed $value): ?int
    {
        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $value = filter_var(trim($value), FILTER_VALIDATE_INT);
        }

        return is_int($value) && $value >= 0 ? $value : null;
    }

    private function nullableId(mixed $value): ?int
    {
        return blank($value) ? null : (int) $value;
    }

    private function fail(string $field, string $reason): never
    {
        throw ValidationException::withMessages([
            $field => __("pondok.keuangan.errors.{$reason}"),
        ]);
    }
}
