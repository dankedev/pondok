<?php

use App\Models\QuranSurah;
use Illuminate\Support\Facades\DB;

/*
| F023 AC-2. Pemeriksaan independen: jumlah ayat per juz dihitung dari `ayah_count` di database dan peta awal juz
| yang ditulis ulang di sini, lalu dibandingkan dengan 30 angka dari rencana (bukan membaca konstanta aplikasi).
*/

const TAHFIDZ_JUZ_STARTS = [
    1 => [1, 1], 2 => [2, 142], 3 => [2, 253], 4 => [3, 93], 5 => [4, 24], 6 => [4, 148], 7 => [5, 82], 8 => [6, 111],
    9 => [7, 88], 10 => [8, 41], 11 => [9, 93], 12 => [11, 6], 13 => [12, 53], 14 => [15, 1], 15 => [17, 1],
    16 => [18, 75], 17 => [21, 1], 18 => [23, 1], 19 => [25, 21], 20 => [27, 56], 21 => [29, 46], 22 => [33, 31],
    23 => [36, 28], 24 => [39, 32], 25 => [41, 47], 26 => [46, 1], 27 => [51, 31], 28 => [58, 1], 29 => [67, 1],
    30 => [78, 1],
];

const TAHFIDZ_AYAH_PER_JUZ = [148, 111, 126, 131, 124, 110, 149, 142, 159, 127, 151, 170, 154, 227, 185, 269, 190, 202, 339, 171, 178, 169, 357, 175, 246, 195, 399, 137, 431, 564];

it('AC-2: seeds 114 surahs with 6236 ayat in total and the known sample counts', function () {
    $ayat = DB::table('quran_surahs')->orderBy('id')->pluck('ayah_count', 'id')->map(fn ($count): int => (int) $count);

    expect($ayat)->toHaveCount(114)
        ->and($ayat->keys()->all())->toBe(range(1, 114))
        ->and($ayat->sum())->toBe(6236)
        ->and($ayat->only([1, 2, 9, 26, 112, 114])->all())->toBe([1 => 7, 2 => 286, 9 => 129, 26 => 227, 112 => 4, 114 => 6]);
});

it('AC-2: counts the expected ayat per juz and keeps every juz start within its surah', function () {
    $ayat = DB::table('quran_surahs')->orderBy('id')->pluck('ayah_count', 'id')->map(fn ($count): int => (int) $count)->all();
    $index = fn (int $surah, int $ayah): int => array_sum(array_slice($ayat, 0, $surah - 1)) + $ayah;

    foreach (TAHFIDZ_JUZ_STARTS as $juz => [$surah, $ayah]) {
        expect($ayah)->toBeLessThanOrEqual($ayat[$surah], "ayat awal juz {$juz} melebihi jumlah ayat surah {$surah}");
    }

    $starts = array_map(fn (array $start): int => $index(...$start), TAHFIDZ_JUZ_STARTS);
    $perJuz = [];
    foreach (range(1, 30) as $juz) {
        $perJuz[] = ($starts[$juz + 1] ?? 6237) - $starts[$juz];
    }

    expect($perJuz)->toBe(TAHFIDZ_AYAH_PER_JUZ);
});

it('AC-2: stores for every surah the juz that contains its first ayat', function () {
    $ayat = DB::table('quran_surahs')->orderBy('id')->pluck('ayah_count', 'id')->map(fn ($count): int => (int) $count)->all();
    $index = fn (int $surah, int $ayah): int => array_sum(array_slice($ayat, 0, $surah - 1)) + $ayah;
    $starts = array_map(fn (array $start): int => $index(...$start), TAHFIDZ_JUZ_STARTS);
    $stored = DB::table('quran_surahs')->orderBy('id')->pluck('juz_start', 'id')->all();

    foreach (array_keys($ayat) as $surah) {
        $first = $index($surah, 1);
        $expected = count(array_filter($starts, fn (int $start): bool => $start <= $first));

        expect((int) $stored[$surah])->toBe($expected, "juz_start surah {$surah}");
    }
});

it('AC-2: maps a surah and ayat to the global index 1..6236', function () {
    expect(QuranSurah::globalIndex(1, 1))->toBe(1)
        ->and(QuranSurah::globalIndex(2, 1))->toBe(8)
        ->and(QuranSurah::globalIndex(114, 6))->toBe(6236);
});
