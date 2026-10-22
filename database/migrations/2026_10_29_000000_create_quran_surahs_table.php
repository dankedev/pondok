<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Data rujukan 114 surah (Hafs, penomoran mushaf Madinah). Ditulis di migration agar ikut
        // `migrate` di instance lama. `juz_start` = juz yang memuat ayat pertama surah.
        Schema::create('quran_surahs', function (Blueprint $table) {
            $table->unsignedSmallInteger('id')->primary();
            $table->string('name');
            $table->unsignedSmallInteger('ayah_count');
            $table->unsignedTinyInteger('juz_start');
        });

        DB::table('quran_surahs')->insert(array_map(
            fn (array $row): array => ['id' => $row[0], 'name' => $row[1], 'ayah_count' => $row[2], 'juz_start' => $row[3]],
            self::SURAHS,
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('quran_surahs');
    }

    /** [id, nama Latin, jumlah ayat, juz awal] */
    private const SURAHS = [
        [1, 'Al-Fatihah', 7, 1],
        [2, 'Al-Baqarah', 286, 1],
        [3, "Ali 'Imran", 200, 3],
        [4, "An-Nisa'", 176, 4],
        [5, "Al-Ma'idah", 120, 6],
        [6, "Al-An'am", 165, 7],
        [7, "Al-A'raf", 206, 8],
        [8, 'Al-Anfal', 75, 9],
        [9, 'At-Taubah', 129, 10],
        [10, 'Yunus', 109, 11],
        [11, 'Hud', 123, 11],
        [12, 'Yusuf', 111, 12],
        [13, "Ar-Ra'd", 43, 13],
        [14, 'Ibrahim', 52, 13],
        [15, 'Al-Hijr', 99, 14],
        [16, 'An-Nahl', 128, 14],
        [17, "Al-Isra'", 111, 15],
        [18, 'Al-Kahf', 110, 15],
        [19, 'Maryam', 98, 16],
        [20, 'Taha', 135, 16],
        [21, "Al-Anbiya'", 112, 17],
        [22, 'Al-Hajj', 78, 17],
        [23, "Al-Mu'minun", 118, 18],
        [24, 'An-Nur', 64, 18],
        [25, 'Al-Furqan', 77, 18],
        [26, "Asy-Syu'ara'", 227, 19],
        [27, 'An-Naml', 93, 19],
        [28, 'Al-Qasas', 88, 20],
        [29, "Al-'Ankabut", 69, 20],
        [30, 'Ar-Rum', 60, 21],
        [31, 'Luqman', 34, 21],
        [32, 'As-Sajdah', 30, 21],
        [33, 'Al-Ahzab', 73, 21],
        [34, "Saba'", 54, 22],
        [35, 'Fatir', 45, 22],
        [36, 'Yasin', 83, 22],
        [37, 'As-Saffat', 182, 23],
        [38, 'Sad', 88, 23],
        [39, 'Az-Zumar', 75, 23],
        [40, 'Gafir', 85, 24],
        [41, 'Fussilat', 54, 24],
        [42, 'Asy-Syura', 53, 25],
        [43, 'Az-Zukhruf', 89, 25],
        [44, 'Ad-Dukhan', 59, 25],
        [45, 'Al-Jasiyah', 37, 25],
        [46, 'Al-Ahqaf', 35, 26],
        [47, 'Muhammad', 38, 26],
        [48, 'Al-Fath', 29, 26],
        [49, 'Al-Hujurat', 18, 26],
        [50, 'Qaf', 45, 26],
        [51, 'Az-Zariyat', 60, 26],
        [52, 'At-Tur', 49, 27],
        [53, 'An-Najm', 62, 27],
        [54, 'Al-Qamar', 55, 27],
        [55, 'Ar-Rahman', 78, 27],
        [56, "Al-Waqi'ah", 96, 27],
        [57, 'Al-Hadid', 29, 27],
        [58, 'Al-Mujadalah', 22, 28],
        [59, 'Al-Hasyr', 24, 28],
        [60, 'Al-Mumtahanah', 13, 28],
        [61, 'As-Saff', 14, 28],
        [62, "Al-Jumu'ah", 11, 28],
        [63, 'Al-Munafiqun', 11, 28],
        [64, 'At-Tagabun', 18, 28],
        [65, 'At-Talaq', 12, 28],
        [66, 'At-Tahrim', 12, 28],
        [67, 'Al-Mulk', 30, 29],
        [68, 'Al-Qalam', 52, 29],
        [69, 'Al-Haqqah', 52, 29],
        [70, "Al-Ma'arij", 44, 29],
        [71, 'Nuh', 28, 29],
        [72, 'Al-Jinn', 28, 29],
        [73, 'Al-Muzzammil', 20, 29],
        [74, 'Al-Muddassir', 56, 29],
        [75, 'Al-Qiyamah', 40, 29],
        [76, 'Al-Insan', 31, 29],
        [77, 'Al-Mursalat', 50, 29],
        [78, "An-Naba'", 40, 30],
        [79, "An-Nazi'at", 46, 30],
        [80, "'Abasa", 42, 30],
        [81, 'At-Takwir', 29, 30],
        [82, 'Al-Infitar', 19, 30],
        [83, 'Al-Mutaffifin', 36, 30],
        [84, 'Al-Insyiqaq', 25, 30],
        [85, 'Al-Buruj', 22, 30],
        [86, 'At-Tariq', 17, 30],
        [87, "Al-A'la", 19, 30],
        [88, 'Al-Gasyiyah', 26, 30],
        [89, 'Al-Fajr', 30, 30],
        [90, 'Al-Balad', 20, 30],
        [91, 'Asy-Syams', 15, 30],
        [92, 'Al-Lail', 21, 30],
        [93, 'Ad-Duha', 11, 30],
        [94, 'Asy-Syarh', 8, 30],
        [95, 'At-Tin', 8, 30],
        [96, "Al-'Alaq", 19, 30],
        [97, 'Al-Qadr', 5, 30],
        [98, 'Al-Bayyinah', 8, 30],
        [99, 'Az-Zalzalah', 8, 30],
        [100, "Al-'Adiyat", 11, 30],
        [101, "Al-Qari'ah", 11, 30],
        [102, 'At-Takasur', 8, 30],
        [103, "Al-'Asr", 3, 30],
        [104, 'Al-Humazah', 9, 30],
        [105, 'Al-Fil', 5, 30],
        [106, 'Quraisy', 4, 30],
        [107, "Al-Ma'un", 7, 30],
        [108, 'Al-Kausar', 3, 30],
        [109, 'Al-Kafirun', 6, 30],
        [110, 'An-Nasr', 3, 30],
        [111, 'Al-Lahab', 5, 30],
        [112, 'Al-Ikhlas', 4, 30],
        [113, 'Al-Falaq', 5, 30],
        [114, 'An-Nas', 6, 30],
    ];
};
