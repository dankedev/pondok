<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Data rujukan 114 surah (read-only, diisi migration). Posisi ayat dipetakan ke indeks
 * global 1–6236 agar rentang setoran lintas surah bisa dibandingkan di PHP (netral database).
 */
class QuranSurah extends Model
{
    public const LAST_SURAH = 114;

    public const LAST_JUZ = 30;

    /** Awal tiap juz: juz => [surah, ayat]. */
    public const JUZ_STARTS = [
        1 => [1, 1], 2 => [2, 142], 3 => [2, 253], 4 => [3, 93], 5 => [4, 24], 6 => [4, 148], 7 => [5, 82], 8 => [6, 111],
        9 => [7, 88], 10 => [8, 41], 11 => [9, 93], 12 => [11, 6], 13 => [12, 53], 14 => [15, 1], 15 => [17, 1],
        16 => [18, 75], 17 => [21, 1], 18 => [23, 1], 19 => [25, 21], 20 => [27, 56], 21 => [29, 46], 22 => [33, 31],
        23 => [36, 28], 24 => [39, 32], 25 => [41, 47], 26 => [46, 1], 27 => [51, 31], 28 => [58, 1], 29 => [67, 1],
        30 => [78, 1],
    ];

    public $incrementing = false;

    public $timestamps = false;

    /** @var array<int, int>|null surah => jumlah ayat sebelum surah itu */
    private static ?array $offsets = null;

    /** @var array<int, int>|null */
    private static ?array $ayahCounts = null;

    protected function casts(): array
    {
        return [
            'ayah_count' => 'integer',
            'juz_start' => 'integer',
        ];
    }

    /**
     * Jumlah ayat per surah, dimuat sekali per proses.
     *
     * @return array<int, int>
     */
    public static function ayahCounts(): array
    {
        return self::$ayahCounts ??= static::query()->orderBy('id')->pluck('ayah_count', 'id')
            ->map(fn (mixed $count): int => (int) $count)->all();
    }

    public static function globalIndex(int $surah, int $ayah): int
    {
        if (self::$offsets === null) {
            $total = 0;
            self::$offsets = [];

            foreach (self::ayahCounts() as $id => $count) {
                self::$offsets[$id] = $total;
                $total += $count;
            }
        }

        return self::$offsets[$surah] + $ayah;
    }

    /**
     * Indeks global ayat pertama dan terakhir sebuah juz.
     *
     * @return array{0: int, 1: int}
     */
    public static function juzRange(int $juz): array
    {
        $end = $juz === self::LAST_JUZ
            ? array_sum(self::ayahCounts())
            : self::globalIndex(...self::JUZ_STARTS[$juz + 1]) - 1;

        return [self::globalIndex(...self::JUZ_STARTS[$juz]), $end];
    }
}
