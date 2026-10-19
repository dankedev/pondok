<?php

namespace App\Actions\Keuangan;

use App\Models\AcademicYear;
use App\Models\FeeType;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Student;
use Carbon\CarbonImmutable;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Memuat satu baris saldo tunggakan awal (santri + jenis biaya + nominal).
 *
 * Hasilnya tagihan biasa berkelompok `Invoice::GROUP_OPENING_BALANCE`, status
 * `belum`, periode bulan import, jatuh tempo akhir bulan lalu. Baris santri
 * yang sama dalam bulan yang sama digabung ke satu tagihan selama belum ada
 * pembayaran. Kombinasi santri + jenis biaya yang sudah punya tagihan saldo
 * awal aktif (periode mana pun) ditolak.
 */
class ImportOpeningBalanceRow
{
    private const MAX_ATTEMPTS = 3;

    private const AMOUNT_PATTERN = '/^[1-9][0-9]{0,11}$/';

    public function __construct(private GenerateInvoiceNumber $generateInvoiceNumber) {}

    /**
     * @param  array<string, mixed>  $row  `nis`, `fee_type`, `amount`
     *
     * @throws RowImportFailedException
     */
    public function handle(array $row): Invoice
    {
        // UnitScope berlaku: santri di luar cakupan jenjang sama dengan tidak ada.
        $student = Student::query()->where('nis', trim((string) $row['nis']))->first()
            ?? $this->fail('student_unknown');

        $feeType = FeeType::query()
            ->active()
            ->where('code', mb_strtoupper(trim((string) $row['fee_type'])))
            ->first() ?? $this->fail('fee_type_unknown');

        $amount = trim((string) $row['amount']);

        if (preg_match(self::AMOUNT_PATTERN, $amount) !== 1) {
            $this->fail('amount_invalid');
        }

        $year = AcademicYear::query()->where('is_active', true)->first() ?? $this->fail('no_active_year');

        $period = CarbonImmutable::now('Asia/Jakarta')->startOfMonth();

        $this->ensureNotImported($student, $feeType);

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn (): Invoice => $this->store($student, $feeType, (int) $amount, $year, $period));
            } catch (UniqueConstraintViolationException) {
                // Tagihan aktif kelompok ini dibuat proses lain: ulang sebagai penambahan item.
                // Selain itu nomor tagihan bentrok: ulang dengan urutan yang dibaca ulang.
                if ($attempt >= self::MAX_ATTEMPTS) {
                    $this->fail('number_conflict');
                }
            }
        }
    }

    private function store(Student $student, FeeType $feeType, int $amount, AcademicYear $year, CarbonImmutable $period): Invoice
    {
        // Diulang di dalam transaksi: proses paralel bisa menyelip setelah cek pertama.
        $this->ensureNotImported($student, $feeType);

        $invoice = $this->openInvoice($student, $period);
        $created = $invoice === null;

        // Log otomatis model dimatikan agar tepat satu log `imported` per baris.
        $invoice = activity()->withoutLogs(function () use ($student, $feeType, $amount, $year, $period, $invoice, $created): Invoice {
            if ($created) {
                $invoice = new Invoice([
                    'invoice_no' => $this->generateInvoiceNumber->handle($period),
                    'student_id' => $student->id,
                    'academic_year_id' => $year->id,
                    'period' => $period,
                    'billing_group' => Invoice::GROUP_OPENING_BALANCE,
                    'total' => 0,
                    'paid_total' => 0,
                    'status' => Invoice::STATUS_UNPAID,
                    'due_on' => $period->subDay(),
                    'generated_by' => auth()->id(),
                ]);
                $invoice->save();
            } elseif ($invoice->paid_total > 0) {
                $this->fail('invoice_paid');
            }

            $invoice->items()->save(new InvoiceItem([
                'fee_type_id' => $feeType->id,
                'description' => __('pondok.invoice.opening_balance_item', ['fee_type' => $feeType->name]),
                'base_amount' => $amount,
                'amount' => $amount,
                'discount_amount' => 0,
                'rate_source' => 'manual',
            ]));

            $invoice->recalculateTotal();

            return $invoice;
        });

        activity('keuangan')
            ->performedOn($invoice)
            ->causedBy(auth()->user())
            ->event('imported')
            ->withProperties([
                'source' => 'opening_balance',
                'items' => [$feeType->code],
                'total' => $invoice->total,
            ])
            ->log('imported');

        return $invoice;
    }

    private function openInvoice(Student $student, CarbonImmutable $period): ?Invoice
    {
        return Invoice::withoutUnitScope()
            ->where('student_id', $student->id)
            ->where('billing_group', Invoice::GROUP_OPENING_BALANCE)
            ->whereDate('period', $period->toDateString())
            ->where('status', '<>', Invoice::STATUS_CANCELLED)
            ->lockForUpdate()
            ->first();
    }

    private function ensureNotImported(Student $student, FeeType $feeType): void
    {
        $exists = Invoice::withoutUnitScope()
            ->where('student_id', $student->id)
            ->where('billing_group', Invoice::GROUP_OPENING_BALANCE)
            ->where('status', '<>', Invoice::STATUS_CANCELLED)
            ->whereHas('items', fn ($query) => $query->where('fee_type_id', $feeType->id))
            ->exists();

        if ($exists) {
            $this->fail('already_imported');
        }
    }

    private function fail(string $reason): never
    {
        throw new RowImportFailedException(__("pondok.invoice.import.errors.{$reason}"));
    }
}
