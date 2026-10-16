<?php

namespace App\Models;

use App\Actions\Keuangan\ResolveFeeRate;
use App\Models\Concerns\HasUnitScope;
use Database\Factories\ApplicantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Pendaftar PSB tanpa akun. Akses publik lewat nomor pendaftaran + token;
 * yang disimpan hanya hash token (`access_token_hash`, tidak fillable dan
 * tersembunyi). `consented_at` diisi server oleh `SubmitApplication`.
 *
 * Global scope cakupan jenjang (`unit_id`). Bagian publik dan Action PSB
 * membaca tanpa scope agar admin yang login di browser sama tidak
 * memengaruhi penomoran, kuota, atau pencarian kredensial.
 */
#[Fillable([
    'admission_wave_id', 'admission_track_id', 'unit_id', 'registration_no', 'name', 'nisn', 'gender',
    'birth_place', 'birth_date', 'previous_school', 'guardian_name', 'guardian_phone', 'email', 'status',
    'reviewed_by', 'reviewed_at', 'review_note',
])]
#[Hidden(['access_token_hash'])]
class Applicant extends Model
{
    /** @use HasFactory<ApplicantFactory> */
    use HasFactory, HasUnitScope, LogsActivity, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'diajukan';

    public const STATUS_VERIFIED = 'diverifikasi';

    public const STATUS_ACCEPTED = 'diterima';

    public const STATUS_RESERVE = 'cadangan';

    public const STATUS_REJECTED = 'ditolak';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_VERIFIED,
        self::STATUS_ACCEPTED,
        self::STATUS_RESERVE,
        self::STATUS_REJECTED,
    ];

    /** Status yang masih bisa ditagih biaya pendaftaran dan diverifikasi berkasnya. */
    public const OPEN_STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_VERIFIED,
    ];

    /**
     * Transisi keputusan panitia (F011): status asal => status tujuan yang sah.
     * `draft` tidak punya transisi keluar; `ditolak` hanya bisa dipulihkan.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_SUBMITTED => [self::STATUS_VERIFIED, self::STATUS_REJECTED],
        self::STATUS_VERIFIED => [self::STATUS_SUBMITTED, self::STATUS_RESERVE, self::STATUS_ACCEPTED, self::STATUS_REJECTED],
        self::STATUS_RESERVE => [self::STATUS_ACCEPTED, self::STATUS_REJECTED],
        self::STATUS_ACCEPTED => [self::STATUS_REJECTED],
        self::STATUS_REJECTED => [self::STATUS_SUBMITTED],
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_SUBMITTED,
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'reviewed_at' => 'datetime',
            'consented_at' => 'datetime',
        ];
    }

    /**
     * Tanpa data pribadi di log.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('psb')
            ->logOnly(['status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function admissionWave(): BelongsTo
    {
        return $this->belongsTo(AdmissionWave::class);
    }

    public function admissionTrack(): BelongsTo
    {
        return $this->belongsTo(AdmissionTrack::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ApplicantDocument::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function feeOverrides(): HasMany
    {
        return $this->hasMany(FeeOverride::class);
    }

    public function student(): HasOne
    {
        return $this->hasOne(Student::class)->withoutGlobalScopes();
    }

    /**
     * Santri hasil daftar ulang pendaftar ini, tanpa cakupan jenjang dan
     * termasuk santri terhapus (F012).
     */
    public function convertedStudent(): ?Student
    {
        return Student::withoutGlobalScopes()->withTrashed()->where('applicant_id', $this->id)->first();
    }

    /**
     * Sudah daftar ulang (punya santri, termasuk yang terhapus).
     */
    public function isConverted(): bool
    {
        return Student::withoutGlobalScopes()->withTrashed()->where('applicant_id', $this->id)->exists();
    }

    /**
     * Tagihan aktif (bukan `batal`) yang memuat item biaya `saat_daftar`.
     */
    public function registrationInvoices(): HasMany
    {
        return $this->invoices()
            ->withoutGlobalScopes()
            ->where('status', '<>', Invoice::STATUS_CANCELLED)
            ->whereHas('items.feeType', fn (Builder $query) => $query->where('frequency', FeeType::FREQUENCY_ON_REGISTRATION));
    }

    /**
     * Tanpa tagihan pendaftaran dianggap lunas.
     */
    public function registrationFeeSettled(): bool
    {
        return ! $this->registrationInvoices()->where('status', '<>', Invoice::STATUS_PAID)->exists();
    }

    /**
     * Tagihan aktif (bukan `batal`) yang memuat item biaya `saat_diterima`.
     */
    public function acceptanceInvoices(): HasMany
    {
        return $this->invoices()
            ->withoutGlobalScopes()
            ->where('status', '<>', Invoice::STATUS_CANCELLED)
            ->whereHas('items.feeType', fn (Builder $query) => $query->where('frequency', FeeType::FREQUENCY_ON_ACCEPTANCE));
    }

    /**
     * Biaya formulir wajib dibayar bila modul keuangan aktif dan ada jenis
     * biaya `saat_daftar` aktif yang bertarif untuk gelombang dan jenjang ini.
     */
    public function registrationFeeRequired(): bool
    {
        if (! Setting::moduleEnabled('keuangan')) {
            return false;
        }

        $wave = AdmissionWave::query()->with('academicYear')->find($this->admission_wave_id);

        if ($wave === null) {
            return false;
        }

        $resolveFeeRate = app(ResolveFeeRate::class);

        return FeeType::query()
            ->active()
            ->where('frequency', FeeType::FREQUENCY_ON_REGISTRATION)
            ->get()
            ->contains(fn (FeeType $type): bool => $resolveFeeRate->handle($type, $wave->academicYear, $this->unit_id, null, null, $wave->id) !== null);
    }

    /**
     * Syarat verifikasi (lebih ketat dari `registrationFeeSettled()`): bila biaya
     * formulir wajib, harus ada tagihan pendaftaran aktif dan semuanya lunas.
     * Tagihan `batal` tidak pernah dihitung lunas.
     */
    public function registrationFeeCleared(): bool
    {
        if (! $this->registrationFeeRequired()) {
            return true;
        }

        $statuses = $this->registrationInvoices()->pluck('status');

        return $statuses->isNotEmpty() && $statuses->every(fn (string $status): bool => $status === Invoice::STATUS_PAID);
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * Syarat wajib jenjang tujuan yang belum punya berkas `valid`, urut `sort_order`.
     *
     * @return Collection<int, AdmissionRequirement>
     */
    public function missingRequiredDocuments(): Collection
    {
        return $this->requirements()
            ->where('is_required', true)
            ->whereNotIn('id', ApplicantDocument::withoutGlobalScopes()
                ->select('admission_requirement_id')
                ->where('applicant_id', $this->id)
                ->where('status', ApplicantDocument::STATUS_VALID))
            ->get();
    }

    public function acceptsUploads(): bool
    {
        return $this->status === self::STATUS_SUBMITTED && $this->registrationFeeSettled();
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /**
     * Sidik token untuk sesi halaman status; berubah saat token diatur ulang.
     */
    public function tokenFingerprint(): string
    {
        return substr(hash('sha256', (string) $this->access_token_hash), 0, 16);
    }

    /**
     * Syarat berkas untuk jenjang tujuan pendaftar.
     */
    public function requirements(): Builder
    {
        return AdmissionRequirement::query()->forUnit($this->unit_id);
    }

    /**
     * Warna badge sesuai design guide §4.
     */
    public function statusColor(): string
    {
        return match ($this->status) {
            self::STATUS_VERIFIED => 'info',
            self::STATUS_ACCEPTED => 'success',
            self::STATUS_RESERVE => 'warning',
            self::STATUS_REJECTED => 'danger',
            default => 'gray',
        };
    }

    public function statusLabel(): string
    {
        return __("pondok.applicant.statuses.{$this->status}");
    }
}
