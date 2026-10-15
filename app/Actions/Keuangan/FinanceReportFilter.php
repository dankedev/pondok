<?php

namespace App\Actions\Keuangan;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Filter laporan keuangan (F008): rentang bulan, jenjang, dan kelompok tagihan.
 *
 * Semua query berangkat dari `Invoice::query()` / `Payment::query()` sehingga
 * global scope cakupan jenjang tetap berlaku. Rentang tanggal memakai
 * pembanding setengah terbuka dengan string `Y-m-d` (tanpa fungsi tanggal SQL)
 * agar portabel dan tetap memakai index `period` / `paid_on`.
 */
final readonly class FinanceReportFilter
{
    public const MAX_MONTHS = 12;

    public const BILLING_GROUP_MAX_LENGTH = 30;

    private function __construct(
        public CarbonImmutable $periodFrom,
        public CarbonImmutable $periodTo,
        public ?int $unitId = null,
        public ?string $billingGroup = null,
    ) {}

    public static function currentMonth(): self
    {
        $month = CarbonImmutable::now(config('app.timezone'))->startOfMonth();

        return new self($month, $month);
    }

    /**
     * @throws ValidationException
     */
    public static function make(mixed $periodFrom, mixed $periodTo, mixed $unitId = null, mixed $billingGroup = null): self
    {
        $errors = [];

        $from = self::parseMonth($periodFrom);
        $to = self::parseMonth($periodTo);

        if ($from === null) {
            $errors['period_from'] = __('pondok.finance_report.errors.period_invalid');
        }

        if ($to === null) {
            $errors['period_to'] = __('pondok.finance_report.errors.period_invalid');
        }

        if ($from !== null && $to !== null) {
            $span = ($to->year - $from->year) * 12 + ($to->month - $from->month);

            if ($span < 0) {
                $errors['period_to'] = __('pondok.finance_report.errors.period_order');
            } elseif ($span >= self::MAX_MONTHS) {
                $errors['period_to'] = __('pondok.finance_report.errors.period_span', ['months' => self::MAX_MONTHS]);
            }
        }

        $unit = self::parseUnit($unitId);

        if ($unit === false) {
            $errors['unit_id'] = __('pondok.finance_report.errors.unit_invalid');
        }

        $group = self::parseBillingGroup($billingGroup);

        if ($group === false) {
            $errors['billing_group'] = __('pondok.finance_report.errors.billing_group_invalid', ['max' => self::BILLING_GROUP_MAX_LENGTH]);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new self($from, $to, $unit, $group);
    }

    public function periodEndExclusive(): CarbonImmutable
    {
        return $this->periodTo->addMonth();
    }

    /**
     * @return list<CarbonImmutable>
     */
    public function months(): array
    {
        $months = [];

        for ($month = $this->periodFrom; $month->lte($this->periodTo); $month = $month->addMonth()) {
            $months[] = $month;
        }

        return $months;
    }

    /**
     * Tagihan dalam cakupan, jenjang, dan kelompok tagihan; tanpa filter periode dan status.
     * Filter jenjang memakai jenjang santri saat ini (termasuk santri diarsipkan),
     * sehingga tagihan pendaftar (`student_id` NULL) tersingkir.
     *
     * @return Builder<Invoice>
     */
    public function invoiceOwnerQuery(): Builder
    {
        return Invoice::query()
            ->when($this->unitId !== null, fn (Builder $query) => $query->whereIn(
                'invoices.student_id',
                Student::withoutGlobalScopes()->where('unit_id', $this->unitId)->select('id'),
            ))
            ->when($this->billingGroup !== null, fn (Builder $query) => $query->where('invoices.billing_group', $this->billingGroup));
    }

    /**
     * Tagihan non-`batal` dengan periode dalam rentang.
     *
     * @return Builder<Invoice>
     */
    public function invoiceQuery(): Builder
    {
        return $this->invoiceOwnerQuery()
            ->where('invoices.status', '<>', Invoice::STATUS_CANCELLED)
            ->where('invoices.period', '>=', $this->periodFrom->toDateString())
            ->where('invoices.period', '<', $this->periodEndExclusive()->toDateString());
    }

    /**
     * Pembayaran berstatus `diterima` saat dibaca, dengan tanggal bayar dalam rentang.
     *
     * @return Builder<Payment>
     */
    public function paymentQuery(): Builder
    {
        return Payment::query()
            ->where('payments.status', Payment::STATUS_RECEIVED)
            ->where('payments.paid_on', '>=', $this->periodFrom->toDateString())
            ->where('payments.paid_on', '<', $this->periodEndExclusive()->toDateString())
            ->when(
                $this->unitId !== null || $this->billingGroup !== null,
                fn (Builder $query) => $query->whereIn('payments.invoice_id', $this->invoiceOwnerQuery()->select('invoices.id')),
            );
    }

    /**
     * @return array{period_from: string, period_to: string, unit_id: ?int, billing_group: ?string}
     */
    public function toArray(): array
    {
        return [
            'period_from' => $this->periodFrom->format('Y-m'),
            'period_to' => $this->periodTo->format('Y-m'),
            'unit_id' => $this->unitId,
            'billing_group' => $this->billingGroup,
        ];
    }

    private static function parseMonth(mixed $value): ?CarbonImmutable
    {
        $timezone = config('app.timezone');

        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::create($value->year, $value->month, 1, 0, 0, 0, $timezone);
        }

        if (! is_string($value) || preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value, $matches) !== 1) {
            return null;
        }

        return CarbonImmutable::create((int) $matches[1], (int) $matches[2], 1, 0, 0, 0, $timezone);
    }

    /**
     * @return int|null|false `false` bila tidak valid atau di luar cakupan user.
     */
    private static function parseUnit(mixed $value): int|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            $id = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $id = (int) $value;
        } else {
            return false;
        }

        return Unit::query()->accessible()->whereKey($id)->exists() ? $id : false;
    }

    /**
     * @return string|null|false `false` bila tidak valid.
     */
    private static function parseBillingGroup(mixed $value): string|null|false
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            return false;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return mb_strlen($value) > self::BILLING_GROUP_MAX_LENGTH ? false : $value;
    }
}
