<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\AdmissionWaveFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Gelombang PSB untuk satu tahun ajaran tujuan. Lintas jenjang. Tidak dihapus,
 * cukup ditutup lewat `is_open`. Rentang tanggal dalam satu tahun ajaran tidak
 * beririsan (dijaga `SaveAdmissionWave`). Kuota dihitung dari pendaftar tidak
 * terhapus selain `draft`, tanpa cakupan jenjang (ditegakkan `SubmitApplication`).
 */
#[Fillable(['academic_year_id', 'name', 'opens_on', 'closes_on', 'quota', 'is_open'])]
class AdmissionWave extends Model
{
    /** @use HasFactory<AdmissionWaveFactory> */
    use HasFactory, LogsActivity;

    protected function casts(): array
    {
        return [
            'opens_on' => 'date',
            'closes_on' => 'date',
            'quota' => 'integer',
            'is_open' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('psb')
            ->logOnly($this->getFillable())
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function feeRates(): HasMany
    {
        return $this->hasMany(FeeRate::class);
    }

    public function applicants(): HasMany
    {
        return $this->hasMany(Applicant::class);
    }

    /**
     * Pendaftar yang mengisi kuota: tidak terhapus, selain `draft`, semua jenjang.
     */
    public function countedApplicants(): int
    {
        return Applicant::withoutUnitScope()
            ->where('admission_wave_id', $this->id)
            ->where('status', '<>', Applicant::STATUS_DRAFT)
            ->count();
    }

    public function isFull(): bool
    {
        return $this->quota !== null && $this->countedApplicants() >= $this->quota;
    }

    /**
     * Gelombang yang menerima pendaftaran pada `$today` dan belum penuh, urut
     * tahun ajaran, tanggal buka, lalu id.
     *
     * @return Collection<int, AdmissionWave>
     */
    public static function openForRegistration(CarbonInterface $today): Collection
    {
        return static::query()
            ->acceptingRegistrationsOn($today)
            ->join('academic_years', 'academic_years.id', '=', 'admission_waves.academic_year_id')
            ->orderBy('academic_years.starts_on')
            ->orderBy('admission_waves.opens_on')
            ->orderBy('admission_waves.id')
            ->select('admission_waves.*')
            ->with('academicYear')
            ->get()
            ->reject(fn (AdmissionWave $wave): bool => $wave->isFull())
            ->values();
    }

    /**
     * Gelombang yang terbuka dan tanggalnya (inklusif) mencakup `$date`.
     */
    public function scopeAcceptingRegistrationsOn(Builder $query, CarbonInterface $date): void
    {
        $day = $date->format('Y-m-d');

        $query->where('is_open', true)
            ->whereDate('opens_on', '<=', $day)
            ->whereDate('closes_on', '>=', $day);
    }

    public function isAcceptingRegistrationsOn(CarbonInterface $date): bool
    {
        $day = $date->format('Y-m-d');

        return $this->is_open
            && $this->opens_on->format('Y-m-d') <= $day
            && $this->closes_on->format('Y-m-d') >= $day;
    }
}
