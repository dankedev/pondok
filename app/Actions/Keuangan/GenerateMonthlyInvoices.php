<?php

namespace App\Actions\Keuangan;

use App\Models\AcademicYear;
use App\Models\FeeOverride;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Membuat tagihan bulanan untuk semua santri aktif (tanpa cakupan jenjang).
 *
 * Per santri, setiap jenis biaya bulanan aktif yang berlaku (semua / mukim /
 * nonmukim, dari kamar aktif saat generator berjalan) dihitung lewat
 * `ResolveFeeRate`, `ResolveFeeOverride`, dan `ApplyFeeOverride`, lalu item
 * dengan `billing_group` sama masuk ke satu tagihan.
 *
 * Idempotent: satu tagihan aktif per santri, periode, dan kelompok; item yang
 * sudah ada tidak pernah diubah (snapshot) dan satu jenis biaya hanya ditagih
 * sekali per santri per periode di antara tagihan aktif. Tagihan `batal`
 * diabaikan, sehingga kombinasi yang dibatalkan mendapat tagihan baru. Item
 * yang belum ada ditambahkan ke tagihan aktif yang belum dibayar.
 *
 * Satu transaksi per kelompok tagihan (santri + grup). Eksekusi yang terputus
 * (timeout, proses dimatikan) aman diulang: kelompok yang sudah di-commit
 * tetap ada dan sisanya dibuat pada eksekusi berikutnya. Lock per periode
 * kedaluwarsa sendiri bila proses mati sebelum melepasnya.
 *
 * Saklar modul tidak dicek di sini (dicek command dan panel).
 */
class GenerateMonthlyInvoices
{
    public const LOCK_SECONDS = 1800;

    public const CHUNK_SIZE = 200;

    public const MAX_ATTEMPTS = 3;

    public const DEFAULT_DUE_DAY = 10;

    public const REASON_MISSING_RATE = 'missing_rate';

    public const REASON_INVOICE_LOCKED = 'invoice_locked';

    private CarbonImmutable $period;

    private AcademicYear $academicYear;

    private ?User $generatedBy;

    private CarbonImmutable $dueOn;

    private string $periodLabel;

    private int $sequence = 0;

    /** @var array<string, FeeRate|null> */
    private array $rates = [];

    /** @var array{created_invoices: int, added_items: int, existing_invoices: int, skipped: list<array{student_id: int, fee_type_id: int, reason: string}>} */
    private array $result;

    public function __construct(
        private ResolveFeeRate $resolveFeeRate,
        private ResolveFeeOverride $resolveFeeOverride,
        private ApplyFeeOverride $applyFeeOverride,
        private GenerateInvoiceNumber $generateInvoiceNumber,
    ) {}

    /**
     * @return array{period: string, created_invoices: int, added_items: int, existing_invoices: int, skipped: list<array{student_id: int, fee_type_id: int, reason: string}>}
     *
     * @throws ValidationException periode tanpa tahun ajaran, atau periode sedang diproses
     * @throws RuntimeException nomor tagihan terus bentrok setelah beberapa percobaan
     */
    public function handle(CarbonInterface $period, ?User $generatedBy = null): array
    {
        $this->period = CarbonImmutable::parse($period->format('Y-m-d'), 'Asia/Jakarta')->startOfMonth();
        $this->generatedBy = $generatedBy;
        $this->academicYear = $this->academicYearFor($this->period);

        $lock = Cache::lock('keuangan:generate-invoices:'.$this->period->format('Y-m'), self::LOCK_SECONDS);

        if (! $lock->get()) {
            $this->fail('already_running');
        }

        try {
            activity()->withoutLogs(fn () => $this->run());
        } finally {
            $lock->release();
        }

        $this->logSummary();

        return ['period' => $this->period->toDateString(), ...$this->result];
    }

    private function run(): void
    {
        $this->result = ['created_invoices' => 0, 'added_items' => 0, 'existing_invoices' => 0, 'skipped' => []];
        $this->rates = [];
        $this->dueOn = $this->period->setDay($this->dueDay());
        $this->periodLabel = $this->period->translatedFormat('F Y');

        $feeTypes = FeeType::query()
            ->active()
            ->where('frequency', FeeType::FREQUENCY_MONTHLY)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        if ($feeTypes->isEmpty()) {
            return;
        }

        $this->sequence = $this->generateInvoiceNumber->lastSequence($this->period);

        Student::withoutUnitScope()
            ->active()
            ->where(fn (Builder $query) => $query
                ->whereNull('entered_on')
                ->orWhereDate('entered_on', '<=', $this->period->endOfMonth()->toDateString()))
            ->with('activeRoom')
            ->chunkById(self::CHUNK_SIZE, fn (Collection $students) => $this->processChunk($students, $feeTypes));
    }

    /**
     * @param  Collection<int, Student>  $students
     * @param  Collection<int, FeeType>  $feeTypes
     */
    private function processChunk(Collection $students, Collection $feeTypes): void
    {
        $studentIds = $students->modelKeys();

        $existing = Invoice::withoutUnitScope()
            ->whereIn('student_id', $studentIds)
            ->whereDate('period', $this->period->toDateString())
            ->where('status', '<>', Invoice::STATUS_CANCELLED)
            ->where('billing_group', '<>', Invoice::GROUP_OPENING_BALANCE)
            ->with('items:id,invoice_id,fee_type_id')
            ->get()
            ->groupBy('student_id');

        $overridePairs = FeeOverride::query()
            ->whereIn('student_id', $studentIds)
            ->whereIn('fee_type_id', $feeTypes->modelKeys())
            ->activeBetween($this->period, $this->period->endOfMonth())
            ->get(['student_id', 'fee_type_id'])
            ->mapWithKeys(fn (FeeOverride $override): array => ["{$override->student_id}:{$override->fee_type_id}" => true])
            ->all();

        foreach ($students as $student) {
            $this->processStudent($student, $feeTypes, $existing->get($student->id, new Collection), $overridePairs);
        }
    }

    /**
     * @param  Collection<int, FeeType>  $feeTypes
     * @param  Collection<int, Invoice>  $invoices  tagihan aktif santri pada periode ini
     * @param  array<string, true>  $overridePairs
     */
    private function processStudent(Student $student, Collection $feeTypes, Collection $invoices, array $overridePairs): void
    {
        $byGroup = $invoices->keyBy('billing_group');
        $billedIn = [];

        foreach ($invoices as $invoice) {
            foreach ($invoice->items as $item) {
                $billedIn[$item->fee_type_id] = $invoice->id;
            }
        }

        $resident = $student->activeRoom !== null;

        /** @var array<string, list<array{fee_type: FeeType, row: array<string, mixed>}>> $groups */
        $groups = [];
        // Tagihan aktif yang dirujuk jenis biaya eksekusi ini (lewat item atau kelompok).
        $relevant = [];

        // Semua nominal dihitung lebih dulu, sebelum transaksi kelompok mana pun dimulai.
        foreach ($feeTypes as $feeType) {
            if (! $this->appliesTo($feeType, $resident)) {
                continue;
            }

            // Satu jenis biaya hanya sekali per santri per periode di antara tagihan aktif,
            // meskipun kelompok tagihannya sudah diubah. Item lama tidak disentuh.
            if (isset($billedIn[$feeType->id])) {
                $relevant[$billedIn[$feeType->id]] = true;

                continue;
            }

            if ($byGroup->has($feeType->billing_group)) {
                $relevant[$byGroup->get($feeType->billing_group)->id] = true;
            }

            $row = $this->itemRow($student, $feeType, isset($overridePairs["{$student->id}:{$feeType->id}"]));

            if ($row === null) {
                $this->skip($student, $feeType, self::REASON_MISSING_RATE);

                continue;
            }

            $groups[$feeType->billing_group][] = ['fee_type' => $feeType, 'row' => $row];
        }

        foreach ($groups as $group => $entries) {
            $invoice = $byGroup->get($group);

            if ($invoice === null) {
                $this->createInvoice($student, $group, array_column($entries, 'row'));

                continue;
            }

            if ($invoice->paid_total > 0) {
                foreach ($entries as $entry) {
                    $this->skip($student, $entry['fee_type'], self::REASON_INVOICE_LOCKED);
                }

                continue;
            }

            DB::transaction(function () use ($invoice, $entries): void {
                $invoice->items()->createMany(array_column($entries, 'row'));
                $invoice->recalculateTotal();
            });

            $this->result['added_items'] += count($entries);
            unset($relevant[$invoice->id]);
        }

        $this->result['existing_invoices'] += count($relevant);
    }

    /**
     * Membuat tagihan baru beserta itemnya dalam satu transaksi. Konflik unique
     * dibedakan dengan cek ulang: tagihan aktif kelompok ini sudah dibuat proses
     * lain (dihitung sudah ada), atau nomor tagihan bentrok (penghitung dimuat
     * ulang lalu dicoba lagi, maksimal `MAX_ATTEMPTS` kali).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function createInvoice(Student $student, string $group, array $rows): void
    {
        for ($attempt = 1; ; $attempt++) {
            $sequence = $this->sequence + 1;

            try {
                DB::transaction(function () use ($student, $group, $rows, $sequence): void {
                    $invoice = new Invoice([
                        'invoice_no' => $this->generateInvoiceNumber->format($this->period, $sequence),
                        'student_id' => $student->id,
                        'academic_year_id' => $this->academicYear->id,
                        'period' => $this->period,
                        'billing_group' => $group,
                        'total' => array_sum(array_column($rows, 'amount')),
                        'paid_total' => 0,
                        'status' => Invoice::STATUS_UNPAID,
                        'due_on' => $this->dueOn,
                        'generated_by' => $this->generatedBy?->id,
                    ]);
                    $invoice->syncStatus();
                    $invoice->save();
                    $invoice->items()->createMany($rows);
                });
            } catch (UniqueConstraintViolationException $exception) {
                if ($this->activeInvoiceExists($student, $group)) {
                    $this->result['existing_invoices']++;

                    return;
                }

                if ($attempt >= self::MAX_ATTEMPTS) {
                    Log::error('Generator tagihan berhenti: nomor tagihan terus bentrok', [
                        'period' => $this->period->toDateString(),
                        'student_id' => $student->id,
                        'billing_group' => $group,
                        'attempts' => $attempt,
                        'error' => $exception->getMessage(),
                    ]);

                    throw new RuntimeException(__('pondok.invoice.errors.number_conflict'), previous: $exception);
                }

                $this->sequence = $this->generateInvoiceNumber->lastSequence($this->period);

                continue;
            }

            $this->sequence = $sequence;
            $this->result['created_invoices']++;

            return;
        }
    }

    /**
     * Baris item siap disimpan, atau null bila tidak ada tarif dan tidak ada keringanan nominal.
     *
     * @return array<string, mixed>|null
     */
    private function itemRow(Student $student, FeeType $feeType, bool $hasOverride): ?array
    {
        $rate = $this->rateFor($student, $feeType);
        $override = $hasOverride ? $this->resolveFeeOverride->forStudent($student, $feeType, $this->period) : null;
        $amounts = $this->applyFeeOverride->handle($rate?->amount, $override);

        if ($amounts === null) {
            return null;
        }

        return [
            'fee_type_id' => $feeType->id,
            'description' => __('pondok.invoice.item_description', ['fee_type' => $feeType->name, 'period' => $this->periodLabel]),
            ...$amounts,
            'rate_source' => match (true) {
                $rate === null => InvoiceItem::RATE_SOURCE_MANUAL,
                $rate->isCohortRate() => InvoiceItem::RATE_SOURCE_COHORT,
                default => InvoiceItem::RATE_SOURCE_UNIT,
            },
        ];
    }

    /**
     * Tarif dimemo per jenis biaya dan profil santri (jenjang, angkatan, asrama) selama satu eksekusi.
     */
    private function rateFor(Student $student, FeeType $feeType): ?FeeRate
    {
        $dormitoryId = $student->activeRoom?->dormitory_id;
        $key = implode(':', [$feeType->id, $student->unit_id, $student->cohort_id ?? '-', $dormitoryId ?? '-']);

        if (! array_key_exists($key, $this->rates)) {
            $this->rates[$key] = $this->resolveFeeRate->handle(
                $feeType,
                $this->academicYear,
                $student->unit_id,
                $student->cohort_id,
                $dormitoryId,
            );
        }

        return $this->rates[$key];
    }

    private function appliesTo(FeeType $feeType, bool $resident): bool
    {
        return match ($feeType->applies_to) {
            FeeType::APPLIES_TO_RESIDENT => $resident,
            FeeType::APPLIES_TO_NON_RESIDENT => ! $resident,
            default => true,
        };
    }

    private function activeInvoiceExists(Student $student, string $group): bool
    {
        return Invoice::withoutUnitScope()
            ->where('student_id', $student->id)
            ->whereDate('period', $this->period->toDateString())
            ->where('billing_group', $group)
            ->where('status', '<>', Invoice::STATUS_CANCELLED)
            ->exists();
    }

    private function skip(Student $student, FeeType $feeType, string $reason): void
    {
        $this->result['skipped'][] = ['student_id' => $student->id, 'fee_type_id' => $feeType->id, 'reason' => $reason];

        Log::warning('Tagihan dilewati', [
            'period' => $this->period->toDateString(),
            'student_id' => $student->id,
            'nis' => $student->nis,
            'fee_type_id' => $feeType->id,
            'fee_type_code' => $feeType->code,
            'reason' => $reason,
        ]);
    }

    private function logSummary(): void
    {
        $logger = activity('keuangan')->event('generated');
        $logger = $this->generatedBy !== null ? $logger->causedBy($this->generatedBy) : $logger->causedByAnonymous();

        $logger
            ->withProperties([
                'period' => $this->period->toDateString(),
                'created_invoices' => $this->result['created_invoices'],
                'added_items' => $this->result['added_items'],
                'existing_invoices' => $this->result['existing_invoices'],
                'skipped_count' => count($this->result['skipped']),
            ])
            ->log(__('pondok.invoice.activity_generated'));
    }

    /**
     * Tanggal jatuh tempo dari `keuangan.due_day` (1-28), selain itu tanggal 10.
     */
    private function dueDay(): int
    {
        $day = Setting::get('keuangan.due_day', self::DEFAULT_DUE_DAY);

        return is_numeric($day) && (int) $day == $day && $day >= 1 && $day <= 28 ? (int) $day : self::DEFAULT_DUE_DAY;
    }

    private function academicYearFor(CarbonImmutable $period): AcademicYear
    {
        $year = AcademicYear::query()
            ->whereDate('starts_on', '<=', $period->toDateString())
            ->whereDate('ends_on', '>=', $period->toDateString())
            ->orderByDesc('starts_on')
            ->first();

        if ($year === null) {
            $this->fail('no_academic_year');
        }

        return $year;
    }

    private function fail(string $reason): never
    {
        throw ValidationException::withMessages([
            'period' => __("pondok.invoice.errors.{$reason}"),
        ]);
    }
}
