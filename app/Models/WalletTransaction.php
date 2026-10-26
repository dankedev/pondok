<?php

namespace App\Models;

use App\Enums\WalletTransactionType;
use App\Models\Concerns\HasUnitScope;
use Database\Factories\WalletTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Satu mutasi uang saku santri, dicatat lewat `RecordWalletTransaction` dan
 * dibatalkan lewat `VoidWalletTransaction` (status `batal`). Tidak pernah
 * diubah atau dihapus; saldo dihitung dari ledger oleh `WalletBalance`.
 *
 * Cakupan jenjang dibaca lewat santri, termasuk yang diarsipkan.
 */
#[Fillable(['student_id', 'type', 'amount', 'occurred_on', 'note', 'status', 'recorded_by', 'cancelled_at', 'cancelled_by', 'cancel_reason'])]
class WalletTransaction extends Model
{
    /** @use HasFactory<WalletTransactionFactory> */
    use HasFactory, HasUnitScope, LogsActivity;

    public const STATUS_SUCCESS = 'berhasil';

    public const STATUS_CANCELLED = 'batal';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_SUCCESS,
    ];

    protected function casts(): array
    {
        return [
            'type' => WalletTransactionType::class,
            'amount' => 'integer',
            'occurred_on' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Jaring pengaman; Action mematikan log otomatis dan menulis satu log manual.
     */
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
        $builder->whereIn(
            $this->qualifyColumn('student_id'),
            Student::withoutGlobalScopes()->whereIn('unit_id', $unitIds)->select('id'),
        );
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isCancellable(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * Warna badge status sesuai design guide §4.
     */
    public function statusColor(): string
    {
        return $this->status === self::STATUS_CANCELLED ? 'danger' : 'success';
    }

    public function statusLabel(): string
    {
        return __("pondok.wallet.statuses.{$this->status}");
    }
}
