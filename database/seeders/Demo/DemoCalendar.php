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

    /**
     * Tahun mulai tahun ajaran tujuan PSB (`S`): tahun depan bila hari ini sudah
     * melewati pembukaan Gelombang I tahun ini (5 Oktober), selain itu tahun ini.
     */
    public function admissionYear(): int
    {
        $year = $this->today->year;

        return $this->today->gte(CarbonImmutable::create($year, 10, 5, 0, 0, 0, self::TIMEZONE)) ? $year + 1 : $year;
    }

    /**
     * Dua gelombang bersambung tahun ajaran tujuan PSB (tanggal brosur untuk 2027/2028,
     * digeser per tahun penuh untuk tahun lain).
     *
     * @return list<array{name: string, opens_on: string, closes_on: string, quota: int}>
     */
    public function admissionWaves(): array
    {
        $shift = $this->admissionYear() - 2027;
        $first = CarbonImmutable::create(2026, 10, 5, 0, 0, 0, self::TIMEZONE)->addYears($shift);
        $second = CarbonImmutable::create(2027, 2, 8, 0, 0, 0, self::TIMEZONE)->addYears($shift);
        $nextFirst = $first->addYear();

        return [
            ['name' => 'Gelombang I', 'opens_on' => $first->toDateString(), 'closes_on' => $second->subDay()->toDateString(), 'quota' => 40],
            ['name' => 'Gelombang II', 'opens_on' => $second->toDateString(), 'closes_on' => $nextFirst->subDay()->toDateString(), 'quota' => 20],
        ];
    }

    public function daysAgo(int $days): CarbonImmutable
    {
        return $this->today->subDays($days);
    }
}
