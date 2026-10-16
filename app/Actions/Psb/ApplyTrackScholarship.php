<?php

namespace App\Actions\Psb;

use App\Actions\Keuangan\SaveFeeOverride;
use App\Models\AdmissionTrack;
use App\Models\Applicant;
use App\Models\FeeOverride;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Menerapkan program beasiswa jalur pendaftar diterima sebagai keringanan
 * milik pendaftar (`applicant_id`) untuk setiap jenis biaya
 * `AdmissionTrack::scholarshipFeeTypes()`. Mode dan nilai di-snapshot dari
 * program saat ini, berlaku mulai hari ini tanpa akhir.
 *
 * Idempotent: keringanan pendaftar yang masih berlaku hari ini atau sesudahnya
 * tidak ditimpa (`already_applied` bila dari program jalur, `existing_override`
 * bila keringanan khusus). Program nonaktif dilewati dengan peringatan.
 *
 * Status dan `deleted_at` dibaca dari baris pendaftar yang dikunci, bukan dari
 * instance masukan. Permission keuangan tidak diperiksa (langkah sistem).
 */
class ApplyTrackScholarship
{
    public const REASON_ALREADY_APPLIED = 'already_applied';

    public const REASON_EXISTING_OVERRIDE = 'existing_override';

    public const REASON_SCHOLARSHIP_INACTIVE = 'scholarship_inactive';

    public function __construct(private SaveFeeOverride $saveFeeOverride) {}

    /**
     * @return array{created: list<int>, skipped: list<array{fee_type_id: int|null, reason: string}>}
     *
     * @throws ValidationException pendaftar tidak lagi diterima atau sudah dihapus
     */
    public function handle(Applicant $applicant, User $by): array
    {
        return DB::transaction(function () use ($applicant, $by): array {
            $locked = Applicant::withoutGlobalScopes()->withTrashed()->lockForUpdate()->find($applicant->id);

            static::ensureAccepted($locked);
            static::ensureNotConverted($locked);

            $result = ['created' => [], 'skipped' => []];
            $track = AdmissionTrack::query()->with('scholarship')->find($locked->admission_track_id);
            $scholarship = $track?->scholarship;

            if ($scholarship === null) {
                return $result;
            }

            if (! $scholarship->is_active) {
                $result['skipped'][] = ['fee_type_id' => null, 'reason' => self::REASON_SCHOLARSHIP_INACTIVE];

                Log::warning('Beasiswa jalur dilewati: program nonaktif', [
                    'registration_no' => $locked->registration_no,
                    'track_code' => $track->code,
                    'scholarship_id' => $scholarship->id,
                ]);

                return $result;
            }

            $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();

            foreach ($track->scholarshipFeeTypes() as $feeType) {
                $existing = FeeOverride::query()
                    ->where('applicant_id', $locked->id)
                    ->where('fee_type_id', $feeType->id)
                    ->activeBetween($today, CarbonImmutable::create(9999, 12, 31))
                    ->orderBy('id')
                    ->first();

                if ($existing !== null) {
                    $result['skipped'][] = [
                        'fee_type_id' => $feeType->id,
                        'reason' => $existing->scholarship_id === $scholarship->id ? self::REASON_ALREADY_APPLIED : self::REASON_EXISTING_OVERRIDE,
                    ];

                    continue;
                }

                $override = $this->saveFeeOverride->handle([
                    'applicant_id' => $locked->id,
                    'fee_type_id' => $feeType->id,
                    'scholarship_id' => $scholarship->id,
                    'mode' => $scholarship->mode,
                    'value' => $scholarship->default_value,
                    'reason' => mb_substr(__('pondok.applicant.track_scholarship_reason', ['track' => $track->name]), 0, 255),
                    'valid_from' => $today,
                    'valid_until' => null,
                ], $by);

                $result['created'][] = $override->id;
            }

            return $result;
        });
    }

    /**
     * Tagihan atau keringanan baru dengan `applicant_id` saja tidak akan pernah
     * pindah ke santri, jadi pendaftar yang sudah daftar ulang ditolak (F012).
     *
     * @throws ValidationException
     */
    public static function ensureNotConverted(Applicant $locked): void
    {
        if ($locked->isConverted()) {
            throw ValidationException::withMessages([
                'applicant' => __('pondok.psb.errors.applicant_converted'),
            ]);
        }
    }

    /**
     * Diterima dan belum menjadi santri.
     *
     * @throws ValidationException
     */
    public static function ensureBillable(?Applicant $locked): void
    {
        static::ensureAccepted($locked);
        static::ensureNotConverted($locked);
    }

    /**
     * @throws ValidationException
     */
    public static function ensureAccepted(?Applicant $locked): void
    {
        if ($locked === null || $locked->trashed() || $locked->status !== Applicant::STATUS_ACCEPTED) {
            throw ValidationException::withMessages([
                'applicant' => __('pondok.psb.errors.applicant_not_accepted'),
            ]);
        }
    }
}
