<?php

namespace App\Models;

use App\Models\Concerns\HasUnitScope;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use LogicException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Tagihan satu santri (atau pendaftar) untuk satu periode dan satu kelompok
 * tagihan. Item adalah snapshot nominal; tagihan tidak dihapus, hanya
 * dibatalkan lewat `CancelInvoice` (status `batal`).
 *
 * `active_marker` adalah generated column (1 untuk tagihan aktif, NULL untuk
 * `batal`) yang diisi database; tidak fillable dan tidak di-cast.
 *
 * Cakupan jenjang dibaca lewat santri (termasuk yang diarsipkan), atau lewat
 * pendaftar (termasuk yang terhapus) untuk tagihan tanpa santri.
 */
#[Fillable(['invoice_no', 'student_id', 'applicant_id', 'academic_year_id', 'period', 'billing_group', 'total', 'paid_total', 'status', 'due_on', 'generated_by'])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, HasUnitScope, LogsActivity;

    public const STATUS_UNPAID = 'belum';

    public const STATUS_PARTIAL = 'sebagian';

    public const STATUS_PAID = 'lunas';

    public const STATUS_CANCELLED = 'batal';

    /** Kelompok tagihan khusus saldo tunggakan awal (F060); dicadangkan, bukan milik jenis biaya. */
    public const GROUP_OPENING_BALANCE = 'SALDO_AWAL';

    public const STATUSES = [
        self::STATUS_UNPAID,
        self::STATUS_PARTIAL,
        self::STATUS_PAID,
        self::STATUS_CANCELLED,
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'total' => 0,
        'paid_total' => 0,
        'status' => self::STATUS_UNPAID,
    ];

    protected function casts(): array
    {
        return [
            'period' => 'date',
            'due_on' => 'date',
            'total' => 'integer',
            'paid_total' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Pengganti check constraint (tidak portabel lewat Blueprint): pemilik wajib ada.
        static::saving(function (Invoice $invoice): void {
            if ($invoice->student_id === null && $invoice->applicant_id === null) {
                throw new LogicException('Tagihan wajib punya santri atau pendaftar.');
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('keuangan')
            ->logOnly(['status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * @param  list<int>  $unitIds
     */
    public function applyUnitScope(Builder $builder, array $unitIds): void
    {
        static::constrainToUnits($builder, $unitIds, $this->getTable());
    }

    /**
     * Tagihan santri jenjang `$unitIds`, atau tagihan pendaftar (tanpa santri)
     * jenjang `$unitIds`. Dipakai juga oleh cakupan `Payment`.
     *
     * @param  list<int>  $unitIds
     */
    public static function constrainToUnits(Builder $builder, array $unitIds, string $table = 'invoices'): void
    {
        $builder->where(fn (Builder $query) => $query
            ->whereIn("{$table}.student_id", Student::withoutGlobalScopes()->whereIn('unit_id', $unitIds)->select('id'))
            ->orWhere(fn (Builder $query) => $query
                ->whereNull("{$table}.student_id")
                ->whereIn("{$table}.applicant_id", Applicant::withoutGlobalScopes()->whereIn('unit_id', $unitIds)->select('id'))));
    }

    /**
     * Label kelompok tagihan untuk tampilan. Kode umum berlabel di
     * `pondok.invoice.billing_groups`; kode buatan admin dibuat terbaca
     * (`PSB_KITAB` menjadi "Psb kitab"). Nilai tersimpan tetap kode.
     */
    public static function billingGroupLabel(string $billingGroup): string
    {
        $key = "pondok.invoice.billing_groups.{$billingGroup}";

        if (Lang::has($key)) {
            return __($key);
        }

        return Str::ucfirst(Str::lower(str_replace('_', ' ', $billingGroup)));
    }

    /**
     * Opsi filter kelompok tagihan dari tagihan yang terlihat, urut label.
     *
     * @return array<string, string> kode => label
     */
    public static function billingGroupOptions(): array
    {
        return static::query()
            ->distinct()
            ->pluck('billing_group')
            ->mapWithKeys(fn (string $billingGroup): array => [$billingGroup => static::billingGroupLabel($billingGroup)])
            ->sort()
            ->all();
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class)->withTrashed();
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * Semua pembayaran, termasuk yang `batal`.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Sisa yang belum dibayar, tidak pernah negatif.
     */
    public function remainingAmount(): int
    {
        return max(0, $this->total - $this->paid_total);
    }

    /**
     * Hitung ulang `total` dari item di database, sinkronkan status, lalu simpan.
     */
    public function recalculateTotal(): void
    {
        $this->total = (int) $this->items()->sum('amount');
        $this->syncStatus();
        $this->save();
    }

    /**
     * Status dari `paid_total` dan `total` (di memori, tanpa menyimpan).
     * Tagihan `batal` tidak pernah berubah; total 0 dianggap lunas.
     */
    public function syncStatus(): void
    {
        if ($this->status === self::STATUS_CANCELLED) {
            return;
        }

        $this->status = match (true) {
            $this->paid_total >= $this->total => self::STATUS_PAID,
            $this->paid_total === 0 => self::STATUS_UNPAID,
            default => self::STATUS_PARTIAL,
        };
    }

    public function isCancellable(): bool
    {
        return $this->status !== self::STATUS_CANCELLED && $this->paid_total === 0;
    }

    /**
     * Lewat jatuh tempo dihitung, tidak disimpan sebagai status.
     */
    public function isOverdue(): bool
    {
        return in_array($this->status, [self::STATUS_UNPAID, self::STATUS_PARTIAL], true)
            && $this->due_on !== null
            && $this->due_on->lt(today());
    }

    /**
     * Warna badge sesuai design guide §4.
     */
    public function statusColor(): string
    {
        if ($this->isOverdue()) {
            return 'danger';
        }

        return match ($this->status) {
            self::STATUS_PAID => 'success',
            self::STATUS_PARTIAL => 'warning',
            self::STATUS_CANCELLED => 'danger',
            default => 'gray',
        };
    }

    public function statusLabel(): string
    {
        if ($this->isOverdue()) {
            return __('pondok.invoice.overdue');
        }

        return __("pondok.invoice.statuses.{$this->status}");
    }
}
