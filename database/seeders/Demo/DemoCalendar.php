<?php

namespace Database\Seeders\Demo;

use Carbon\CarbonImmutable;

/**
 * Tanggal demo yang diturunkan dari hari ini (Asia/Jakarta), sehingga hasil
 * seeding sama untuk tanggal yang sama.
 *
 * `Y` = tahun mulai tahun ajaran aktif (Juli ke atas = tahun ini, selain itu tahun lalu).
 * `P` = tahun ajaran lalu, `A` = aktif, `N` = tujuan gelombang PSB.
 */
final class DemoCalendar
{
    public const TIMEZONE = 'Asia/Jakarta';

    public readonly CarbonImmutable $today;

    public readonly int $year;

    public function __construct()
    {
        $this->today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $this->year = $this->today->month >= 7 ? $this->today->year : $this->today->year - 1;
    }

    /**
     * @return array{name: string, starts_on: string, ends_on: string}
     */
    public function academicYear(int $offset): array
    {
        $start = $this->year + $offset;

        return [
            'name' => $start.'/'.($start + 1),
            'starts_on' => "{$start}-07-01",
            'ends_on' => ($start + 1).'-06-30',
        ];
    }

    public function entryYear(int $entryOffset): int
    {
        return $this->year + $entryOffset;
    }

    public function nis(int $entryOffset, int $sequence): string
    {
        return $this->entryYear($entryOffset).str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    public function enteredOn(int $entryOffset): string
    {
        return $this->entryYear($entryOffset).'-07-01';
    }

    public function birthDate(int $birthYear, string $monthDay): string
    {
        return $birthYear.'-'.$monthDay;
    }

    /**
     * Empat periode tagihan: tiga bulan lalu sampai bulan berjalan.
     *
     * @return list<CarbonImmutable>
     */
    public function billingPeriods(): array
    {
        $current = $this->today->startOfMonth();

        return [$current->subMonthsNoOverflow(3), $current->subMonthsNoOverflow(2), $current->subMonthNoOverflow(), $current];
    }

    public function daysAgo(int $days): CarbonImmutable
    {
        return $this->today->subDays($days);
    }
}
