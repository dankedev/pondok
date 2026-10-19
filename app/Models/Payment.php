<?php

namespace App\Models;

use App\Models\Concerns\HasUnitScope;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Number;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Pembayaran satu tagihan, dicatat lewat `RecordPayment` dan dibatalkan lewat
 * `CancelPayment` (status `batal`). Tidak pernah dihapus: nomor kuitansi,
 * alokasi, dan bukti transfer tetap tersimpan setelah pembatalan.
 *
 * Cakupan jenjang dibaca lewat tagihan, lalu santri (termasuk yang
 * diarsipkan) atau pendaftar (termasuk yang terhapus).
 */
#[Fillable(['receipt_no', 'invoice_id', 'amount', 'paid_on', 'method', 'proof_path', 'gateway_ref', 'received_by', 'signer_name', 'signer_title', 'note', 'status', 'cancelled_at', 'cancelled_by', 'cancel_reason'])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory, HasUnitScope, LogsActivity;

    public const STATUS_RECEIVED = 'diterima';

    public const STATUS_CANCELLED = 'batal';

    public const STATUSES = [
        self::STATUS_RECEIVED,
        self::STATUS_CANCELLED,
    ];

    public const METHOD_CASH = 'tunai';

    public const METHOD_TRANSFER = 'transfer';

    /** Dicadangkan untuk payment gateway (F020). */
    public const METHOD_GATEWAY = 'gateway';

    public const METHODS = [
        self::METHOD_CASH,
        self::METHOD_TRANSFER,
        self::METHOD_GATEWAY,
    ];

    /** Metode yang boleh dicatat bendahara dari panel. */
    public const MANUAL_METHODS = [
        self::METHOD_CASH,
        self::METHOD_TRANSFER,
    ];

    /** Disk dan folder bukti transfer. */
    public const PROOF_DISK = 'private';

    public const PROOF_DIRECTORY = 'payment-proofs';

    private const UNITS = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];

    private const SCALES = [
        1_000_000_000_000 => 'triliun',
        1_000_000_000 => 'miliar',
        1_000_000 => 'juta',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_RECEIVED,
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_on' => 'date',
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
            $this->qualifyColumn('invoice_id'),
            Invoice::withoutGlobalScopes()
                ->tap(fn (Builder $query) => Invoice::constrainToUnits($query, $unitIds))
                ->select('id'),
        );
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isCancellable(): bool
    {
        return $this->status === self::STATUS_RECEIVED;
    }

    /**
     * Warna badge sesuai design guide §4.
     */
    public function statusColor(): string
    {
        return $this->status === self::STATUS_CANCELLED ? 'danger' : 'success';
    }

    public function statusLabel(): string
    {
        return __("pondok.payment.statuses.{$this->status}");
    }

    public function methodLabel(): string
    {
        return __("pondok.payment.methods.{$this->method}");
    }

    /**
     * Nominal dalam huruf, misalnya "Lima ratus ribu rupiah".
     */
    public function amountInWords(): string
    {
        return static::spellRupiah((int) $this->amount);
    }

    /**
     * Terbilang rupiah deterministik (tanpa ICU, tidak bergantung locale aplikasi).
     */
    public static function spellRupiah(int $amount): string
    {
        $words = $amount === 0 ? 'nol' : static::spell($amount);

        return ucfirst(__('pondok.payment.amount_in_words', ['words' => $words], 'id'));
    }

    /**
     * Uang rupiah dengan spasi biasa, misalnya "Rp 500.000" (design guide §5).
     */
    public static function formatRupiah(int $amount): string
    {
        $formatted = (string) Number::currency($amount, in: 'IDR', locale: 'id', precision: 0);

        return (string) preg_replace('/[\x{00A0}\x{202F}]/u', ' ', $formatted);
    }

    private static function spell(int $number): string
    {
        foreach (self::SCALES as $scale => $name) {
            if ($number >= $scale) {
                return trim(static::spell(intdiv($number, $scale)).' '.$name.' '.static::spell($number % $scale));
            }
        }

        return match (true) {
            $number >= 2000 => trim(static::spell(intdiv($number, 1000)).' ribu '.static::spell($number % 1000)),
            $number >= 1000 => trim('seribu '.static::spell($number - 1000)),
            $number >= 200 => trim(static::spell(intdiv($number, 100)).' ratus '.static::spell($number % 100)),
            $number >= 100 => trim('seratus '.static::spell($number - 100)),
            $number >= 20 => trim(static::spell(intdiv($number, 10)).' puluh '.static::spell($number % 10)),
            $number >= 12 => static::spell($number - 10).' belas',
            default => self::UNITS[$number],
        };
    }
}
