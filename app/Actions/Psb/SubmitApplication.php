<?php

namespace App\Actions\Psb;

use App\Models\AcademicYear;
use App\Models\AdmissionTrack;
use App\Models\AdmissionWave;
use App\Models\Applicant;
use App\Models\Setting;
use App\Models\Unit;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Pendaftaran online PSB tanpa akun.
 *
 * `normalize()` merapikan dan memeriksa masukan (juga dipakai per langkah
 * wizard). `handle()` menyimpan pendaftar `diajukan` dengan nomor
 * `PSB-YYYY-NNNN`, hash token, dan waktu persetujuan data dari server.
 *
 * Setiap percobaan adalah satu transaksi baru yang diawali kunci tahun ajaran
 * lalu gelombang (penghitung nomor per tahun ajaran, kuota per gelombang).
 * Bentrok nomor ditangkap di luar transaksi lalu dicoba lagi. Semua bacaan
 * tanpa cakupan jenjang agar admin yang login di browser sama tidak
 * memengaruhi hasil. Tagihan `saat_daftar` dibuat setelah commit; kegagalannya
 * hanya dicatat dan tidak membatalkan pendaftaran.
 */
class SubmitApplication
{
    public const MAX_ATTEMPTS = 3;

    public const FIELDS = [
        'unit_id', 'admission_track_id', 'admission_wave_id', 'name', 'gender', 'birth_place', 'birth_date',
        'nisn', 'previous_school', 'guardian_name', 'guardian_phone', 'email', 'consent',
    ];

    public function __construct(
        private GenerateApplicantNumber $generateApplicantNumber,
        private GenerateRegistrationInvoices $generateRegistrationInvoices,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{applicant: Applicant, token: string}
     *
     * @throws ValidationException
     * @throws RuntimeException nomor pendaftaran terus bentrok
     */
    public function handle(array $data): array
    {
        $data = $this->normalize($data);
        $token = ResetApplicantToken::newToken();
        // Dibaca di luar transaksi: di dalamnya tidak boleh ada bacaan biasa sebelum kunci
        // (snapshot REPEATABLE READ MySQL terbentuk pada bacaan pertama).
        $academicYearId = (int) AdmissionWave::query()->whereKey($data['admission_wave_id'])->value('academic_year_id');

        for ($attempt = 1; ; $attempt++) {
            try {
                $applicant = DB::transaction(fn (): Applicant => $this->create($data, $token, $academicYearId));

                break;
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    Log::error('Pendaftaran PSB gagal: nomor pendaftaran terus bentrok', [
                        'admission_wave_id' => $data['admission_wave_id'],
                        'attempts' => $attempt,
                        'error' => $exception->getMessage(),
                    ]);

                    throw new RuntimeException(__('pondok.psb.errors.number_conflict'), previous: $exception);
                }
            }
        }

        if (Setting::moduleEnabled('keuangan')) {
            try {
                $this->generateRegistrationInvoices->handle($applicant);
            } catch (Throwable $exception) {
                Log::error('Tagihan pendaftaran PSB gagal dibuat', [
                    'registration_no' => $applicant->registration_no,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return ['applicant' => $applicant, 'token' => $token];
    }

    /**
     * Rapikan dan periksa masukan. Semua field yang gagal (dalam `$only`, atau
     * semua field) dilaporkan sekaligus.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>|null  $only
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function normalize(array $data, ?array $only = null): array
    {
        $fields = $only ?? self::FIELDS;
        $normalized = [];
        $errors = [];

        foreach ($fields as $field) {
            $result = $this->normalizeField($field, $data[$field] ?? null);

            if (is_array($result) && isset($result['error'])) {
                $errors[$field] = __("pondok.psb.errors.{$result['error']}");

                continue;
            }

            $normalized[$field] = $result['value'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    /**
     * @return array{value: mixed}|array{error: string}
     */
    private function normalizeField(string $field, mixed $value): array
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return match ($field) {
            'unit_id' => $this->existing($value, fn (int $id): bool => Unit::query()->whereKey($id)->exists(), 'unit_not_found'),
            'admission_track_id' => $this->track($value),
            'admission_wave_id' => $this->existing($value, fn (int $id): bool => AdmissionWave::query()->whereKey($id)->exists(), 'wave_not_found'),
            'name' => $this->text((string) preg_replace('/\s+/u', ' ', $text), 255, 'applicant_name_invalid'),
            'gender' => in_array($text, ['L', 'P'], true) ? ['value' => $text] : ['error' => 'gender_invalid'],
            'birth_place' => $this->text($text, 100, 'birth_place_invalid'),
            'birth_date' => $this->birthDate($text),
            'nisn' => match (true) {
                $text === '' => ['value' => null],
                preg_match('/^\d{10}$/', $text) === 1 => ['value' => $text],
                default => ['error' => 'nisn_invalid'],
            },
            'previous_school' => $this->text($text, 255, 'previous_school_invalid'),
            'guardian_name' => $this->text($text, 255, 'guardian_name_invalid'),
            'guardian_phone' => $this->phone($text),
            'email' => $this->email($text),
            'consent' => filter_var($value, FILTER_VALIDATE_BOOLEAN) === true ? ['value' => true] : ['error' => 'consent_required'],
            default => ['value' => $value],
        };
    }

    /**
     * @return array{value: int}|array{error: string}
     */
    private function existing(mixed $value, callable $exists, string $reason): array
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);

        return $id !== false && $exists($id) ? ['value' => $id] : ['error' => $reason];
    }

    /**
     * @return array{value: int}|array{error: string}
     */
    private function track(mixed $value): array
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        $track = $id === false ? null : AdmissionTrack::query()->find($id);

        return match (true) {
            $track === null => ['error' => 'track_not_found'],
            ! $track->is_active => ['error' => 'track_inactive'],
            default => ['value' => $track->id],
        };
    }

    /**
     * @return array{value: string}|array{error: string}
     */
    private function text(string $value, int $max, string $reason): array
    {
        return $value !== '' && mb_strlen($value) <= $max ? ['value' => $value] : ['error' => $reason];
    }

    /**
     * Tanggal `Y-m-d` yang valid dan sebelum hari ini.
     *
     * @return array{value: string}|array{error: string}
     */
    private function birthDate(string $value): array
    {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $value)
            : false;

        if ($date === false || $date->format('Y-m-d') !== $value || $value >= now('Asia/Jakarta')->toDateString()) {
            return ['error' => 'birth_date_invalid'];
        }

        return ['value' => $value];
    }

    /**
     * Nomor HP disimpan tanpa format dengan awalan `08`.
     *
     * @return array{value: string}|array{error: string}
     */
    private function phone(string $value): array
    {
        $phone = (string) preg_replace('/[\s\-.()]/', '', $value);
        $phone = (string) preg_replace('/^\+?62/', '0', $phone);

        return preg_match('/^08\d{7,12}$/', $phone) === 1 ? ['value' => $phone] : ['error' => 'phone_invalid'];
    }

    /**
     * @return array{value: string|null}|array{error: string}
     */
    private function email(string $value): array
    {
        if ($value === '') {
            return ['value' => null];
        }

        $email = mb_strtolower($value);

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false && mb_strlen($email) <= 255
            ? ['value' => $email]
            : ['error' => 'email_invalid'];
    }

    /**
     * Satu percobaan di dalam transaksi: kunci dulu, baru baca pendaftar.
     *
     * @param  array<string, mixed>  $data
     */
    private function create(array $data, string $token, int $academicYearId): Applicant
    {
        AcademicYear::query()->lockForUpdate()->find($academicYearId);
        $wave = AdmissionWave::query()->lockForUpdate()->find($data['admission_wave_id']);

        if ($wave === null) {
            $this->fail('admission_wave_id', 'wave_not_found');
        }

        if (! $wave->isAcceptingRegistrationsOn(now('Asia/Jakarta'))) {
            $this->fail('admission_wave_id', 'wave_not_accepting');
        }

        if ($wave->isFull()) {
            $this->fail('admission_wave_id', 'wave_full');
        }

        $duplicate = Applicant::withoutUnitScope()
            ->where('admission_wave_id', $wave->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($data['name'])])
            ->whereDate('birth_date', $data['birth_date'])
            ->where('guardian_phone', $data['guardian_phone'])
            ->exists();

        if ($duplicate) {
            $this->fail('name', 'duplicate_application');
        }

        $applicant = new Applicant([
            ...collect($data)->except('consent')->all(),
            'registration_no' => $this->generateApplicantNumber->handle($wave),
            'status' => Applicant::STATUS_SUBMITTED,
        ]);
        $applicant->forceFill([
            'access_token_hash' => ResetApplicantToken::hash($token),
            'consented_at' => now(),
        ]);
        $applicant->save();

        return $applicant;
    }

    private function fail(string $field, string $reason): never
    {
        throw ValidationException::withMessages([
            $field => __("pondok.psb.errors.{$reason}"),
        ]);
    }
}
