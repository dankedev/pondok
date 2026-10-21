<?php

use App\Actions\Core\DumpDatabase;
use App\Models\Document;
use App\Models\Dormitory;
use App\Models\FeeRate;
use App\Models\Student;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
| F042 AC-2: dump data murni (PHP) yang bisa dipulihkan. Dijalankan di SQLite; jalur MySQL dicek manual.
*/

beforeEach(function () {
    $this->sqlPath = tempnam(sys_get_temp_dir(), 'f042-dump-');
});

afterEach(function () {
    @unlink($this->sqlPath);
});

/**
 * Isi `activity_log` dengan baris kecil dan baris besar (±10 KB).
 */
function dumpSeedActivityLog(int $small, int $big = 0): void
{
    $now = now()->toDateTimeString();
    $rows = [];

    for ($i = 0; $i < $small; $i++) {
        $rows[] = ['log_name' => 'uji', 'description' => "baris kecil {$i}", 'created_at' => $now, 'updated_at' => $now];
    }

    for ($i = 0; $i < $big; $i++) {
        $rows[] = ['log_name' => 'uji', 'description' => str_repeat('b', 10000), 'created_at' => $now, 'updated_at' => $now];
    }

    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('activity_log')->insert($chunk);
    }
}

/**
 * Pernyataan SQL (dipotong di tiap `INSERT INTO`/`DELETE FROM`) milik satu tabel.
 *
 * @return list<string>
 */
function dumpStatements(string $sql, string $keyword, string $table): array
{
    $parts = preg_split('/(?=INSERT INTO |DELETE FROM )/', $sql, -1, PREG_SPLIT_NO_EMPTY) ?: [];

    return array_values(array_filter(
        $parts,
        fn (string $part): bool => preg_match('/^'.preg_quote($keyword, '/').' [`"]?'.preg_quote($table, '/').'[`"]?[\s(;]/', $part) === 1,
    ));
}

it('AC-2: restores every table after migrate:fresh, including encrypted, generated and id-less tables', function () {
    $this->seed(UnitSeeder::class);
    $unit = Unit::query()->firstOrFail();
    $student = Student::factory()->create(['name' => "Ahmad O'Neil \"Kutip\" \\ garis\nbaru \u{2603}", 'nisn' => null]);
    $document = Document::factory()->forStudent($student)->create(['number' => '3201010101010001']);
    $rawNumber = DB::table('documents')->where('id', $document->id)->value('number');
    $rate = FeeRate::factory()->create(['amount' => 250000]);
    $user = User::factory()->create();
    $user->units()->attach($unit->id);
    dumpSeedActivityLog(1200);

    expect(DB::table('dormitories')->count())->toBe(0);

    // migrate:fresh memakai VACUUM yang tidak boleh berjalan di dalam transaksi RefreshDatabase.
    while (DB::transactionLevel() > 0) {
        DB::commit();
    }

    try {
        $counts = app(DumpDatabase::class)->handle($this->sqlPath);

        expect(Artisan::call('migrate:fresh', ['--force' => true]))->toBe(0);

        // Baris yang diisi migration/seeder sebelum impor harus hilang oleh `DELETE FROM` tabel kosong.
        Dormitory::factory()->create();

        DB::unprepared((string) file_get_contents($this->sqlPath));

        foreach ($counts as $table => $count) {
            expect(DB::table($table)->count())->toBe($count, "Jumlah baris {$table}");
        }

        $restored = Student::query()->withTrashed()->findOrFail($student->id);

        expect($counts['activity_log'])->toBeGreaterThanOrEqual(1200)
            ->and($counts['unit_user'])->toBe(1)
            ->and($counts['dormitories'])->toBe(0)
            ->and(DB::table('dormitories')->count())->toBe(0)
            ->and($restored->name)->toBe("Ahmad O'Neil \"Kutip\" \\ garis\nbaru \u{2603}")
            ->and($restored->nisn)->toBeNull()
            ->and(DB::table('documents')->where('id', $document->id)->value('number'))->toBe($rawNumber)
            ->and(Document::query()->findOrFail($document->id)->number)->toBe('3201010101010001')
            ->and(DB::table('fee_rates')->where('id', $rate->id)->value('amount'))->toBe(250000)
            ->and(DB::table('unit_user')->where('user_id', $user->id)->where('unit_id', $unit->id)->exists())->toBeTrue();
    } finally {
        Artisan::call('migrate:fresh', ['--force' => true]);
    }
});

it('AC-2: writes several multi-row inserts, none above the statement size limit', function () {
    dumpSeedActivityLog(1050, 150);

    $counts = app(DumpDatabase::class)->handle($this->sqlPath);
    $sql = (string) file_get_contents($this->sqlPath);
    $inserts = dumpStatements($sql, 'INSERT INTO', 'activity_log');

    expect($counts['activity_log'])->toBe(1200)
        ->and(count($inserts))->toBeGreaterThan(1);

    foreach ($inserts as $statement) {
        expect(strlen($statement))->toBeLessThanOrEqual(DumpDatabase::MAX_STATEMENT_BYTES);
    }
});

it('AC-2: deletes before inserting for every dumped table, even empty ones, and wraps with the sqlite pragmas', function () {
    app(DumpDatabase::class)->handle($this->sqlPath);
    $sql = (string) file_get_contents($this->sqlPath);

    expect(DB::table('dormitories')->count())->toBe(0)
        ->and(dumpStatements($sql, 'DELETE FROM', 'dormitories'))->toHaveCount(1)
        ->and(dumpStatements($sql, 'INSERT INTO', 'dormitories'))->toBe([])
        ->and($sql)->toContain('PRAGMA foreign_keys=OFF;')
        ->and(strrpos($sql, 'PRAGMA foreign_keys=ON;'))->toBeGreaterThan(strrpos($sql, 'INSERT INTO'));
});

it('AC-2: leaves out the rebuildable tables and the generated columns', function () {
    DB::table('sessions')->insert(['id' => 'abc', 'payload' => 'x', 'last_activity' => 1]);
    DB::table('cache')->insert(['key' => 'k', 'value' => 'v', 'expiration' => 99999999999]);
    DB::table('failed_jobs')->insert(['uuid' => 'u-1', 'connection' => 'c', 'queue' => 'q', 'payload' => 'p', 'exception' => 'e']);
    FeeRate::factory()->create();

    $counts = app(DumpDatabase::class)->handle($this->sqlPath);
    $sql = (string) file_get_contents($this->sqlPath);

    foreach (['migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs'] as $table) {
        expect($counts)->not->toHaveKey($table)
            ->and(dumpStatements($sql, 'INSERT INTO', $table))->toBe([])
            ->and(dumpStatements($sql, 'DELETE FROM', $table))->toBe([]);
    }

    expect($counts['fee_rates'])->toBe(1)
        ->and($sql)->not->toContain('cohort_key');
});
