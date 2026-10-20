<?php

namespace App\Actions\Psb;

use App\Actions\Akademik\AttachGuardian;
use App\Actions\Akademik\CreateStudent;
use App\Actions\Akademik\FindOrCreateCohort;
use App\Actions\Akademik\GenerateStudentNumber;
use App\Models\AdmissionWave;
use App\Models\Applicant;
use App\Models\Document;
use App\Models\FeeOverride;
use App\Models\Guardian;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Daftar ulang PSB: mengubah pendaftar `diterima` yang sudah memenuhi syarat
 * pembayaran menjadi santri dalam satu transaksi. Santri baru dibuat dari data
 * pendaftar (NIS manual atau otomatis, angkatan tahun masuk gelombang), wali
 * disalin atau dipakai ulang lalu dijadikan kontak tagihan, semua tagihan dan
 * keringanan pendaftar mendapat `student_id` (`applicant_id` tetap), dan
 * berkas valid disalin ke dokumen santri/wali.
 *
 * Setiap percobaan memakai transaksinya sendiri; gagal = rollback dan file
 * salinan dihapus (kompensasi). Pelanggaran unique ditangkap di luar
 * transaksi: pendaftar yang ternyata sudah punya santri → `already_converted`,
 * NIS manual → `nis_taken`, selain itu (NIS otomatis atau angkatan dibuat
 * bersamaan) dicoba ulang sampai `MAX_ATTEMPTS`.
 *
 * Jangan dipanggil di dalam transaksi pemanggil: loop percobaan dan
 * penanganan unique violation hanya benar bila transaksinya milik Action ini.
 */
class ReRegisterApplicant
{
    public const MAX_ATTEMPTS = 3;

    public const DEFAULT_RELATION = 'wali';

    public const ADDRESS_MAX_LENGTH = 1000;

    public const NIS_PATTERN = '/^[A-Za-z0-9.\-\/]{1,30}$/';

    public function __construct(
        private CheckReRegistrationPayment $checkPayment,
        private FindOrCreateCohort $findOrCreateCohort,
        private GenerateStudentNumber $generateStudentNumber,
        private CreateStudent $createStudent,
        private ResolveApplicantGuardian $resolveGuardian,
        private AttachGuardian $attachGuardian,
        private CopyApplicantDocuments $copyDocuments,
    ) {}

    /**
     * @param  array{nis?: string|null, entered_on?: CarbonInterface|string|null, relation?: string|null, address?: string|null}  $data
     * @return array{student: Student, guardian: Guardian, guardian_reused: bool, invoices: int, fee_overrides: int, documents_copied: int, documents_skipped: list<array{applicant_document_id: int, reason: string}>}
     *
     * @throws ValidationException
     * @throws RuntimeException NIS otomatis terus bentrok, file gagal disalin, atau tagihan berubah di sela kunci
     */
    public function handle(Applicant $applicant, User $by, array $data = []): array
    {
        $input = $this->normalize($data);

        for ($attempt = 1; ; $attempt++) {
            $copiedPaths = [];

            try {
                return DB::transaction(function () use ($applicant, $by, $input, &$copiedPaths): array {
                    return $this->convert($applicant->id, $by, $input, $copiedPaths);
                });
            } catch (Throwable $exception) {
                $this->removeCopiedFiles($copiedPaths);

                if (! $exception instanceof UniqueConstraintViolationException) {
                    throw $exception;
                }

                if ($applicant->isConverted()) {
                    $this->fail('applicant', 'already_converted');
                }

                if ($input['nis'] !== null) {
                    $this->fail('nis', 'nis_taken');
                }

                if ($attempt >= self::MAX_ATTEMPTS) {
                    Log::error('Daftar ulang gagal: NIS otomatis terus bentrok', [
                        'registration_no' => $applicant->registration_no,
                        'attempts' => $attempt,
                    ]);

                    throw new RuntimeException(__('pondok.psb.errors.student_number_conflict'), previous: $exception);
                }
            }
        }
    }

    /**
     * Tanggal masuk bawaan: awal bulan depan atau awal tahun ajaran gelombang, mana yang lebih akhir.
     */
    public static function defaultEnteredOn(Applicant $applicant): CarbonImmutable
    {
        $startsOn = AdmissionWave::query()->with('academicYear')->find($applicant->admission_wave_id)?->academicYear?->starts_on;
        $earliest = static::earliestEnteredOn();

        if ($startsOn === null) {
            return $earliest;
        }

        $startsOn = CarbonImmutable::parse(CarbonImmutable::parse($startsOn)->toDateString(), config('app.timezone'));

        return $startsOn->gt($earliest) ? $startsOn : $earliest;
    }

    /**
     * Awal bulan depan: tagihan bulanan santri tidak pernah dimulai di bulan berjalan.
     */
    public static function earliestEnteredOn(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone'))->startOfMonth()->addMonthNoOverflow();
    }

    /**
     * @param  array{nis: string|null, entered_on: CarbonImmutable|null, relation: string, address: string|null}  $input
     * @param  list<string>  $copiedPaths
     * @return array{student: Student, guardian: Guardian, guardian_reused: bool, invoices: int, fee_overrides: int, documents_copied: int, documents_skipped: list<array{applicant_document_id: int, reason: string}>}
     */
    private function convert(int $applicantId, User $by, array $input, array &$copiedPaths): array
    {
        $locked = Applicant::withoutGlobalScopes()->withTrashed()->lockForUpdate()->find($applicantId);

        if ($locked === null || $locked->trashed()) {
            $this->fail('applicant', 'applicant_not_found');
        }

        if ($locked->status !== Applicant::STATUS_ACCEPTED) {
            $this->fail('status', 'applicant_not_accepted');
        }

        if ($locked->isConverted()) {
            $this->fail('applicant', 'already_converted');
        }

        $invoiceIds = Invoice::withoutUnitScope()
            ->where('applicant_id', $locked->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->all();

        $this->ensurePaymentMet($locked);
        $this->ensureNumbersAvailable($locked, $input['nis']);

        $unit = Unit::query()->findOrFail($locked->unit_id);
        $enteredOn = $input['entered_on'] ?? static::defaultEnteredOn($locked);
        $entryYear = static::entryYear($locked);
        $cohort = $this->findOrCreateCohort->handle($unit, $entryYear);

        $student = $this->createStudent->handle([
            'unit_id' => $unit->id,
            'cohort_id' => $cohort->id,
            'applicant_id' => $locked->id,
            'nis' => $input['nis'] ?? $this->generateStudentNumber->handle($unit, $entryYear),
            'nisn' => $locked->nisn,
            'name' => $locked->name,
            'gender' => $locked->gender,
            'birth_place' => $locked->birth_place,
            'birth_date' => $locked->birth_date,
            'address' => $input['address'],
            'status' => Student::STATUS_ACTIVE,
            'entered_on' => $enteredOn->toDateString(),
        ]);

        ['guardian' => $guardian, 'reused' => $reused] = $this->resolveGuardian->handle($locked);
        $this->attachGuardian->handle($student, $guardian, $input['relation']);

        $overrideIds = activity()->withoutLogs(fn (): array => $this->transferBilling($locked, $student, $invoiceIds));
        $documents = $this->copyDocuments->handle($locked, $student, $guardian, $by, $copiedPaths);

        activity('psb')
            ->performedOn($locked)
            ->causedBy($by)
            ->event('converted')
            ->withProperties([
                'student_id' => $student->id,
                'nis' => $student->nis,
                'guardian_id' => $guardian->id,
                'guardian_reused' => $reused,
                'invoices' => count($invoiceIds),
                'fee_overrides' => count($overrideIds),
                'documents_copied' => count($documents['copied']),
            ])
            ->log(__('pondok.applicant.activity_converted'));

        activity('keuangan')
            ->performedOn($student)
            ->causedBy($by)
            ->event('applicant_billing_transferred')
            ->withProperties([
                'applicant_id' => $locked->id,
                'invoice_ids' => $invoiceIds,
                'fee_override_ids' => $overrideIds,
            ])
            ->log(__('pondok.applicant.activity_billing_transferred'));

        return [
            'student' => $student,
            'guardian' => $guardian,
            'guardian_reused' => $reused,
            'invoices' => count($invoiceIds),
            'fee_overrides' => count($overrideIds),
            'documents_copied' => count($documents['copied']),
            'documents_skipped' => $documents['skipped'],
        ];
    }

    /**
     * Mengisi `student_id` tagihan yang dikunci dan semua keringanan pendaftar.
     * Jumlah baris yang berubah harus sama dengan jumlah id; selain itu ada proses
     * lain yang mengubah data di sela kunci.
     *
     * @param  list<int>  $invoiceIds
     * @return list<int> id keringanan yang dipindahkan
     */
    private function transferBilling(Applicant $locked, Student $student, array $invoiceIds): array
    {
        $overrideIds = FeeOverride::query()
            ->where('applicant_id', $locked->id)
            ->whereNull('student_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->all();

        $values = ['student_id' => $student->id, 'updated_at' => now()];
        $invoices = $invoiceIds === [] ? 0 : Invoice::withoutUnitScope()->whereKey($invoiceIds)->whereNull('student_id')->update($values);
        $overrides = $overrideIds === [] ? 0 : FeeOverride::query()->whereKey($overrideIds)->whereNull('student_id')->update($values);

        if ($invoices !== count($invoiceIds) || $overrides !== count($overrideIds)) {
            throw new RuntimeException(__('pondok.psb.errors.billing_transfer_mismatch'));
        }

        return $overrideIds;
    }

    private function ensurePaymentMet(Applicant $locked): void
    {
        $payment = $this->checkPayment->handle($locked);

        if ($payment['meets']) {
            return;
        }

        $reason = $payment['reason'] ?? CheckReRegistrationPayment::REASON_MIN_PAYMENT;

        throw ValidationException::withMessages([
            'payment' => __("pondok.psb.errors.{$reason}", [
                'paid' => Payment::formatRupiah($payment['paid']),
                'required' => Payment::formatRupiah($payment['required']),
            ]),
        ]);
    }

    private function ensureNumbersAvailable(Applicant $locked, ?string $nis): void
    {
        $students = fn () => Student::withoutGlobalScopes()->withTrashed();

        if (filled($locked->nisn) && $students()->where('nisn', $locked->nisn)->exists()) {
            $this->fail('nisn', 'nisn_taken');
        }

        if ($nis !== null && $students()->where('nis', $nis)->exists()) {
            $this->fail('nis', 'nis_taken');
        }
    }

    /**
     * Tahun masuk = tahun awal tahun ajaran gelombang (2027/2028 → 2027).
     */
    public static function entryYear(Applicant $applicant): int
    {
        $wave = AdmissionWave::query()->with('academicYear')->findOrFail($applicant->admission_wave_id);

        return CarbonImmutable::parse($wave->academicYear->starts_on)->year;
    }

    /**
     * Validasi masukan sebelum transaksi; gagal = tidak ada perubahan.
     *
     * @param  array<string, mixed>  $data
     * @return array{nis: string|null, entered_on: CarbonImmutable|null, relation: string, address: string|null}
     */
    private function normalize(array $data): array
    {
        $relation = blank($data['relation'] ?? null) ? self::DEFAULT_RELATION : $data['relation'];

        if (! in_array($relation, Guardian::RELATIONS, true)) {
            $this->fail('relation', 'relation_invalid');
        }

        $address = trim((string) ($data['address'] ?? ''));

        if (mb_strlen($address) > self::ADDRESS_MAX_LENGTH) {
            $this->fail('address', 'address_too_long');
        }

        $nis = trim((string) ($data['nis'] ?? ''));

        if ($nis !== '' && preg_match(self::NIS_PATTERN, $nis) !== 1) {
            $this->fail('nis', 'nis_invalid');
        }

        return [
            'nis' => $nis === '' ? null : $nis,
            'entered_on' => $this->enteredOn($data['entered_on'] ?? null),
            'relation' => $relation,
            'address' => $address === '' ? null : $address,
        ];
    }

    private function enteredOn(mixed $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            $date = CarbonImmutable::parse($value->toDateString(), config('app.timezone'));
        } else {
            $parsed = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

            if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
                $this->fail('entered_on', 'date_invalid');
            }

            $date = CarbonImmutable::parse($value, config('app.timezone'));
        }

        $earliest = static::earliestEnteredOn();

        if ($date->lt($earliest)) {
            throw ValidationException::withMessages([
                'entered_on' => __('pondok.psb.errors.entered_on_too_early', ['date' => $earliest->translatedFormat('j M Y')]),
            ]);
        }

        return $date;
    }

    /**
     * @param  list<string>  $paths
     */
    private function removeCopiedFiles(array $paths): void
    {
        foreach ($paths as $path) {
            try {
                Storage::disk(Document::DISK)->delete($path);
            } catch (Throwable) {
                Log::warning('Daftar ulang: file salinan gagal dihapus', ['path' => $path]);
            }
        }
    }

    private function fail(string $field, string $reason): never
    {
        throw ValidationException::withMessages([
            $field => __("pondok.psb.errors.{$reason}"),
        ]);
    }
}
