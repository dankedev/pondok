<?php

namespace App\Models;

use Database\Factories\FeeTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Jenis biaya (SPP, asrama, uang pangkal, ...). Lintas jenjang; tidak dihapus,
 * cukup dinonaktifkan.
 */
#[Fillable(['code', 'name', 'frequency', 'applies_to', 'billing_group', 'priority', 'is_active'])]
class FeeType extends Model
{
    /** @use HasFactory<FeeTypeFactory> */
    use HasFactory, LogsActivity;

    public const FREQUENCY_MONTHLY = 'bulanan';

    public const FREQUENCY_YEARLY = 'tahunan';

    public const FREQUENCY_ON_REGISTRATION = 'saat_daftar';

    public const FREQUENCY_ON_ACCEPTANCE = 'saat_diterima';

    public const FREQUENCY_MANUAL = 'manual';

    public const FREQUENCIES = [
        self::FREQUENCY_MONTHLY,
        self::FREQUENCY_YEARLY,
        self::FREQUENCY_ON_REGISTRATION,
        self::FREQUENCY_ON_ACCEPTANCE,
        self::FREQUENCY_MANUAL,
    ];

    /** Frekuensi biaya PSB; hanya jenis ini yang boleh punya tarif per gelombang. */
    public const ADMISSION_FREQUENCIES = [
        self::FREQUENCY_ON_REGISTRATION,
        self::FREQUENCY_ON_ACCEPTANCE,
    ];

    public const APPLIES_TO_ALL = 'semua';

    public const APPLIES_TO_RESIDENT = 'mukim';

    public const APPLIES_TO_NON_RESIDENT = 'nonmukim';

    public const APPLIES_TO = [
        self::APPLIES_TO_ALL,
        self::APPLIES_TO_RESIDENT,
        self::APPLIES_TO_NON_RESIDENT,
    ];

    public const DEFAULT_PRIORITY = 100;

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('keuangan')
            ->logOnly($this->getFillable())
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function rates(): HasMany
    {
        return $this->hasMany(FeeRate::class);
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(FeeOverride::class);
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function isAdmissionFee(): bool
    {
        return in_array($this->frequency, self::ADMISSION_FREQUENCIES, true);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
