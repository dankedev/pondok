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
use Illuminate\Support\Arr;
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
     * `signers` opsional: staff pengganti per indeks slot (`null` = pakai hasil `ResolveLetterSigners`).
     *
     * @param  array{type?: string|null, signers?: list<int|string|null>|null, purpose?: string|null}  $data
     */
    public function handle(Student $student, array $data, User $issuer): IssuedLetter
    {
        $type = $this->type($data['type'] ?? null);
        $student = $this->student($student, $type);
        $signers = $this->signers($type, $student, $data['signers'] ?? null);
        $purpose = $this->purpose($data['purpose'] ?? null, $type);

        return $this->issueWithRetry([
            'type' => $type,
            'student_id' => $student->getKey(),
            'signed_by_staff_id' => $signers[0]['staff']->getKey(),
            'purpose' => $purpose,
            'snapshot' => $this->snapshot($student, $signers),
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
            ->withProperties([
                'letter_no' => $letter->letter_no,
                'type' => $letter->type,
                'signers' => array_map(fn (array $signer): array => Arr::only($signer, ['label', 'name']), $letter->snapshot['signers']),
            ])
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

    /**
     * Staff final per slot (override per indeks, atau hasil resolusi), berurutan sesuai slot.
     *
     * @return non-empty-list<array{label: string, staff: Staff}>
     */
    private function signers(string $type, Student $student, mixed $overrides): array
    {
        $slots = app(ResolveLetterSigners::class)->handle($type, $student);
        $overrides = is_array($overrides) ? array_values($overrides) : null;

        if ($overrides !== null && count($overrides) !== count($slots)) {
            $this->fail('signers', 'signers_invalid');
        }

        $result = [];
        $used = [];

        foreach ($slots as $i => $slot) {
            $key = "signers.{$i}.staff_id";
            $id = $overrides[$i] ?? $slot['staff_id'];

            if ($id === null || $id === '') {
                $this->fail($key, 'signer_required');
            }

            $staff = is_numeric($id) ? Staff::query()->find((int) $id) : null;

            if ($staff === null || ! $staff->is_active) {
                $this->fail($key, 'signer_invalid');
            }

            if (in_array($staff->getKey(), $used, true)) {
                $this->fail($key, 'signer_duplicate');
            }

            $used[] = $staff->getKey();
            $result[] = ['label' => $slot['label'], 'staff' => $staff];
        }

        return $result;
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
     * @param  non-empty-list<array{label: string, staff: Staff}>  $signers
     * @return array{student: array<string, mixed>, signers: list<array<string, mixed>>, school: array<string, mixed>}
     */
    private function snapshot(Student $student, array $signers): array
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
            'signers' => array_map(fn (array $signer): array => [
                'label' => $signer['label'],
                'name' => $signer['staff']->name,
                'position' => $signer['staff']->position,
                'nip' => filled($signer['staff']->nip) ? (string) $signer['staff']->nip : null,
            ], $signers),
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
