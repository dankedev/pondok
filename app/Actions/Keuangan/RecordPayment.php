<?php

namespace App\Actions\Keuangan;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Mencatat pembayaran (tunai atau transfer) untuk satu tagihan, maksimal
 * sebesar sisa tagihan. Nominal dialokasikan otomatis ke item menurut
 * prioritas jenis biaya, lalu terbayar item, terbayar tagihan, dan status
 * tagihan diperbarui dalam satu transaksi.
 *
 * Status tagihan dan sisa divalidasi dari baris tagihan yang dikunci, bukan
 * dari instance yang mungkin basi. Nomor kuitansi yang bentrok dicoba ulang
 * dengan penghitung yang dibaca ulang, maksimal `MAX_ATTEMPTS` kali.
 *
 * Tepat satu baris activity per pembayaran (log otomatis model dimatikan).
 */
class RecordPayment
{
    public const MAX_ATTEMPTS = 3;

    public const NOTE_MAX_LENGTH = 1000;

    public const PROOF_MAX_KILOBYTES = 2048;

    /** @var list<string> */
    public const PROOF_EXTENSIONS = ['jpg', 'jpeg', 'png', 'pdf'];

    public function __construct(
        private AllocatePaymentToItems $allocatePaymentToItems,
        private GenerateReceiptNumber $generateReceiptNumber,
    ) {}

    /**
     * @param  array{amount: int|string, paid_on: string|CarbonInterface, method: string, proof?: UploadedFile|null, note?: string|null}  $data
     */
    public function handle(Invoice $invoice, User $user, array $data): Payment
    {
        $amount = $this->amount($data['amount'] ?? null);
        $method = $this->method($data['method'] ?? null);
        $paidOn = $this->paidOn($data['paid_on'] ?? null);
        $note = $this->note($data['note'] ?? null);
        $proof = $this->proof($data['proof'] ?? null, $method);

        $recordedAt = CarbonImmutable::now('Asia/Jakarta');
        $proofPath = $proof?->store(Payment::PROOF_DIRECTORY.'/'.$recordedAt->format('Y/m'), Payment::PROOF_DISK);

        if ($proof !== null && ! is_string($proofPath)) {
            throw new RuntimeException('Bukti transfer gagal disimpan.');
        }

        try {
            $payment = $this->recordWithRetry($invoice, $user, [
                'amount' => $amount,
                'method' => $method,
                'paid_on' => $paidOn,
                'note' => $note,
                'proof_path' => $proofPath ?: null,
            ], $recordedAt);
        } catch (Throwable $exception) {
            if (is_string($proofPath)) {
                Storage::disk(Payment::PROOF_DISK)->delete($proofPath);
            }

            throw $exception;
        }

        return $payment;
    }

    /**
     * @param  array{amount: int, method: string, paid_on: string, note: string|null, proof_path: string|null}  $attributes
     */
    private function recordWithRetry(Invoice $invoice, User $user, array $attributes, CarbonImmutable $recordedAt): Payment
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn (): Payment => $this->record($invoice, $user, $attributes, $recordedAt));
            } catch (UniqueConstraintViolationException $exception) {
                // Satu-satunya unique yang bisa bentrok adalah `receipt_no`: alokasi baru
                // selalu milik pembayaran baru.
                if ($attempt >= self::MAX_ATTEMPTS) {
                    Log::error('Pencatatan pembayaran berhenti: nomor kuitansi terus bentrok', [
                        'invoice_id' => $invoice->getKey(),
                        'attempts' => $attempt,
                        'error' => $exception->getMessage(),
                    ]);

                    throw new RuntimeException(__('pondok.payment.errors.receipt_number_conflict'), previous: $exception);
                }
            }
        }
    }

    /**
     * @param  array{amount: int, method: string, paid_on: string, note: string|null, proof_path: string|null}  $attributes
     */
    private function record(Invoice $invoice, User $user, array $attributes, CarbonImmutable $recordedAt): Payment
    {
        $current = Invoice::withoutUnitScope()->lockForUpdate()->findOrFail($invoice->getKey());

        if ($current->status === Invoice::STATUS_CANCELLED) {
            $this->fail('invoice', 'invoice_cancelled');
        }

        $remaining = $current->remainingAmount();

        if ($remaining === 0) {
            $this->fail('invoice', 'already_paid');
        }

        if ($attributes['amount'] > $remaining) {
            $this->fail('amount', 'exceeds_remaining', ['remaining' => Payment::formatRupiah($remaining)]);
        }

        $items = $current->items()->with('feeType')->get();
        $allocations = $this->allocatePaymentToItems->handle($items, $attributes['amount']);
        $receiptNo = $this->generateReceiptNumber->format(
            $recordedAt,
            $this->generateReceiptNumber->lastSequence($recordedAt) + 1,
        );
        $oldStatus = $current->status;

        $payment = activity()->withoutLogs(function () use ($current, $user, $attributes, $items, $allocations, $receiptNo): Payment {
            $payment = Payment::query()->create([
                ...$attributes,
                'receipt_no' => $receiptNo,
                'invoice_id' => $current->getKey(),
                'received_by' => $user->getKey(),
                'status' => Payment::STATUS_RECEIVED,
            ]);

            $payment->setRelation('allocations', $payment->allocations()->createMany($allocations));

            $itemsById = $items->keyBy(fn (InvoiceItem $item): int => (int) $item->getKey());

            foreach ($allocations as $allocation) {
                $item = $itemsById[$allocation['invoice_item_id']];
                $item->paid_amount += $allocation['amount'];
                $item->save();
            }

            $current->paid_total += $attributes['amount'];
            $current->syncStatus();
            $current->save();

            return $payment;
        });

        $feeTypeIds = $items->mapWithKeys(fn (InvoiceItem $item): array => [(int) $item->getKey() => (int) $item->fee_type_id]);

        activity('keuangan')
            ->performedOn($payment)
            ->causedBy($user)
            ->event('created')
            ->withProperties([
                'receipt_no' => $receiptNo,
                'invoice_id' => $current->getKey(),
                'invoice_no' => $current->invoice_no,
                'amount' => $attributes['amount'],
                'method' => $attributes['method'],
                'paid_on' => $attributes['paid_on'],
                'allocations' => array_map(fn (array $allocation): array => [
                    'invoice_item_id' => $allocation['invoice_item_id'],
                    'fee_type_id' => $feeTypeIds[$allocation['invoice_item_id']],
                    'amount' => $allocation['amount'],
                ], $allocations),
                'invoice_status' => ['old' => $oldStatus, 'new' => $current->status],
            ])
            ->log(__('pondok.payment.activity_recorded'));

        $invoice->setRawAttributes($current->getAttributes(), sync: true);

        return $payment;
    }

    private function amount(mixed $value): int
    {
        $amount = is_int($value) ? $value : filter_var(is_string($value) ? trim($value) : $value, FILTER_VALIDATE_INT);

        if ($amount === false || $amount < 1) {
            $this->fail('amount', 'amount_invalid');
        }

        return $amount;
    }

    private function method(mixed $value): string
    {
        if (! in_array($value, Payment::MANUAL_METHODS, true)) {
            $this->fail('method', 'method_invalid');
        }

        return $value;
    }

    /**
     * Tanggal `Y-m-d`; tidak boleh setelah hari ini menurut Asia/Jakarta.
     */
    private function paidOn(mixed $value): string
    {
        if ($value instanceof CarbonInterface) {
            $date = $value->toDateString();
        } elseif (is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', trim($value), $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            $date = "{$parts[1]}-{$parts[2]}-{$parts[3]}";
        } else {
            $this->fail('paid_on', 'paid_on_invalid');
        }

        if ($date > CarbonImmutable::now('Asia/Jakarta')->toDateString()) {
            $this->fail('paid_on', 'paid_on_future');
        }

        return $date;
    }

    private function note(mixed $value): ?string
    {
        $note = trim((string) $value);

        if ($note === '') {
            return null;
        }

        if (mb_strlen($note) > self::NOTE_MAX_LENGTH) {
            $this->fail('note', 'note_too_long');
        }

        return $note;
    }

    private function proof(mixed $value, string $method): ?UploadedFile
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if ($method !== Payment::METHOD_TRANSFER) {
            $this->fail('proof', 'proof_not_allowed');
        }

        $valid = $value instanceof UploadedFile && Validator::make(
            ['proof' => $value],
            ['proof' => ['file', 'mimes:'.implode(',', self::PROOF_EXTENSIONS), 'extensions:'.implode(',', self::PROOF_EXTENSIONS), 'max:'.self::PROOF_MAX_KILOBYTES]],
        )->passes();

        if (! $valid) {
            $this->fail('proof', 'proof_invalid');
        }

        return $value;
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function fail(string $field, string $reason, array $replace = []): never
    {
        throw ValidationException::withMessages([
            $field => __("pondok.payment.errors.{$reason}", $replace),
        ]);
    }
}
