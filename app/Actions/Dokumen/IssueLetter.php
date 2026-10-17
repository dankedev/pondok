<?php

namespace App\Actions\Dokumen;

use App\Actions\Konten\GetPrintIdentity;
use App\Models\AcademicYear;
use App\Models\IssuedLetter;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Menerbitkan surat keluar untuk santri: nomor otomatis per (jenis, tahun),
 * kode verifikasi acak, dan isi surat yang dibekukan di `snapshot`.
 *
 * Cakupan jenjang mengikuti user login (global scope `Student`), seperti
 * Action modul lain; tanpa user login (console/queue) tidak disaring.
 *
 * Nomor atau kode yang bentrok (unique index) serta deadlock/lock wait diulang
 * dengan transaksi baru, maksimal `MAX_ATTEMPTS` kali. Pengecualian ditangkap
 * di luar transaksi agar aman untuk Postgres. Tepat satu activity `issued`
 * per surat, ditulis di dalam transaksi percobaan yang berhasil.
 *
 * Tidak `final`: test mengganti `verificationCode()` lewat subkelas di container.
 */
class IssueLetter
{
    use DetectsConcurrencyErrors;

    public const MAX_ATTEMPTS = 3;

    public const TIMEZONE = 'Asia/Jakarta';

    public function __construct(private readonly GetPrintIdentity $printIdentity) {}

    /**
     * @param  array{type?: string|null, signed_by_staff_id?: int|string|null, purpose?: string|null}  $data
     */
    public function handle(Student $student, array $data, User $issuer): IssuedLetter
    {
        $type = $this->type($data['type'] ?? null);
        $student = $this->student($student, $type);
        $signer = $this->signer($data['signed_by_staff_id'] ?? null);
        $purpose = $this->purpose($data['purpose'] ?? null, $type);

        return $this->issueWithRetry([
            'type' => $type,
            'student_id' => $student->getKey(),
            'signed_by_staff_id' => $signer->getKey(),
            'purpose' => $purpose,
            'snapshot' => $this->snapshot($student, $signer),
            'issued_by' => $issuer->getKey(),
        ], $issuer);
    }

    /**
     * Kode verifikasi baru untuk satu percobaan penerbitan.
     */
    public function verificationCode(): string
    {
        return IssuedLetter::newVerificationCode();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function issueWithRetry(array $attributes, User $issuer): IssuedLetter
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(fn (): IssuedLetter => $this->issue($attributes, $issuer));
            } catch (Throwable $exception) {
                if (! $exception instanceof UniqueConstraintViolationException && ! $this->causedByConcurrencyError($exception)) {
                    throw $exception;
                }

                $lastError = $exception;
            }
        }

        Log::error('Penerbitan surat berhenti: nomor atau kode surat terus bentrok', [
            'type' => $attributes['type'],
            'attempts' => self::MAX_ATTEMPTS,
            'error' => isset($lastError) ? $lastError->getMessage() : null,
        ]);

        $this->fail('letter_no', 'number_conflict');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function issue(array $attributes, User $issuer): IssuedLetter
    {
        $numbering = app(LetterNumbering::class);
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $sequence = $numbering->nextSequence($attributes['type'], $today->year);

        $letter = new IssuedLetter;
        $letter->forceFill([
            ...$attributes,
            'letter_no' => $numbering->format($attributes['type'], $today, $sequence),
            'sequence_year' => $today->year,
            'sequence' => $sequence,
            'issued_on' => $today->toDateString(),
            'verification_code' => $this->verificationCode(),
            'status' => IssuedLetter::STATUS_ISSUED,
        ])->save();

        activity('dokumen')
            ->performedOn($letter)
            ->causedBy($issuer)
            ->event('issued')
            ->withProperties(['letter_no' => $letter->letter_no, 'type' => $letter->type])
            ->log('issued');

        return $letter;
    }

    private function type(mixed $value): string
    {
        if (! is_string($value) || ! in_array($value, IssuedLetter::TYPES, true)) {
            $this->fail('type', 'letter_type_invalid');
        }

        return $value;
    }

    /**
     * Santri yang tidak diarsipkan, terlihat oleh cakupan user login, dan berstatus sesuai jenis surat.
     */
    private function student(Student $student, string $type): Student
    {
        if ($student->trashed()) {
            $this->fail('student', 'student_archived');
        }

        $current = Student::query()->with(['unit', 'currentEnrollment.classroom'])->find($student->getKey());

        if ($current === null) {
            $this->fail('student', 'student_not_found');
        }

        if (! in_array($current->status, IssuedLetter::ALLOWED_STUDENT_STATUSES[$type], true)) {
            $this->fail('student', 'student_status_not_allowed', [
                'status' => mb_strtolower(__("pondok.student.statuses.{$current->status}")),
                'type' => mb_strtolower(IssuedLetter::typeLabelFor($type)),
            ]);
        }

        return $current;
    }

    private function signer(mixed $value): Staff
    {
        $signer = is_numeric($value) ? Staff::query()->find((int) $value) : null;

        if ($signer === null || ! $signer->is_active) {
            $this->fail('signed_by_staff_id', 'signer_invalid');
        }

        return $signer;
    }

    private function purpose(mixed $value, string $type): ?string
    {
        if (! in_array($type, IssuedLetter::TYPES_REQUIRING_PURPOSE, true)) {
            return null;
        }

        $purpose = is_string($value) ? trim($value) : '';

        if ($purpose === '') {
            $this->fail('purpose', 'purpose_required');
        }

        if (mb_strlen($purpose) > IssuedLetter::PURPOSE_MAX_LENGTH) {
            $this->fail('purpose', 'purpose_too_long');
        }

        return $purpose;
    }

    /**
     * Isi surat yang dibekukan: data minimal santri, penandatangan, dan lembaga (tanpa wali, NIK, alamat santri).
     *
     * @return array{student: array<string, mixed>, signer: array<string, mixed>, school: array<string, mixed>}
     */
    private function snapshot(Student $student, Staff $signer): array
    {
        return [
            'student' => [
                'name' => $student->name,
                'nis' => $student->nis,
                'nisn' => $student->nisn,
                'gender' => $student->gender,
                'birth_place' => $student->birth_place,
                'birth_date' => $student->birth_date?->format('Y-m-d'),
                'unit_name' => $student->unit?->name,
                'classroom_name' => $student->currentEnrollment?->classroom?->name,
                'academic_year_name' => AcademicYear::current()?->name,
                'status' => $student->status,
            ],
            'signer' => [
                'name' => $signer->name,
                'position' => $signer->position,
                'nip' => filled($signer->nip) ? (string) $signer->nip : null,
            ],
            'school' => $this->printIdentity->handle(),
        ];
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function fail(string $field, string $reason, array $replace = []): never
    {
        throw ValidationException::withMessages([
            $field => __("pondok.dokumen.errors.{$reason}", $replace),
        ]);
    }
}
