<?php

use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Models\Cohort;
use App\Models\Document;
use App\Models\FeeOverride;
use App\Models\Guardian;
use App\Models\Invoice;
use App\Models\Student;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

/*
| F053: daftar ulang bersamaan di MySQL nyata, dua proses PHP terpisah (`tests/Fixtures/f053-reregister.php`).
| Hanya berjalan bila `F053_MYSQL_DATABASE` diisi (nama wajib berakhiran `_test`; isinya dihapus `migrate:fresh`),
| plus `F053_MYSQL_HOST`, `_PORT`, `_USERNAME`, `_PASSWORD`. Tanpa env ini test di-skip, jadi suite SQLite/CI tetap.
*/

beforeEach(function () {
    $database = (string) getenv('F053_MYSQL_DATABASE');

    if ($database === '') {
        $this->markTestSkipped('Uji konkurensi MySQL F053: isi F053_MYSQL_DATABASE untuk menjalankan.');
    }

    if (! str_ends_with($database, '_test')) {
        $this->fail("F053_MYSQL_DATABASE harus berakhiran _test (isinya dihapus migrate:fresh), diberikan: {$database}");
    }

    $this->mysqlEnv = [
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => getenv('F053_MYSQL_HOST') ?: '127.0.0.1',
        'DB_PORT' => getenv('F053_MYSQL_PORT') ?: '3306',
        'DB_DATABASE' => $database,
        'DB_USERNAME' => getenv('F053_MYSQL_USERNAME') ?: 'root',
        'DB_PASSWORD' => (string) getenv('F053_MYSQL_PASSWORD'),
    ];

    $this->defaultConnection = config('database.default');

    config([
        'database.connections.f053_mysql' => [
            ...config('database.connections.mysql'),
            'host' => $this->mysqlEnv['DB_HOST'],
            'port' => $this->mysqlEnv['DB_PORT'],
            'database' => $database,
            'username' => $this->mysqlEnv['DB_USERNAME'],
            'password' => $this->mysqlEnv['DB_PASSWORD'],
        ],
        'database.default' => 'f053_mysql',
    ]);

    Artisan::call('migrate:fresh', ['--database' => 'f053_mysql', '--force' => true]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->f = daftarUlangFixture();
});

afterEach(function () {
    // Rollback RefreshDatabase saat teardown memakai koneksi default; kembalikan ke SQLite agar transaksinya ditutup.
    if (isset($this->defaultConnection)) {
        config(['database.default' => $this->defaultConnection]);
        DB::purge('f053_mysql');
    }
});

/**
 * Jalankan daftar ulang `$applicants` di proses terpisah yang mulai bersamaan; kembalikan keluaran JSON tiap proses.
 *
 * @param  list<Applicant>  $applicants
 * @return list<array<string, mixed>>
 */
function f053Race(array $applicants, int $holdMs): array
{
    $startAt = microtime(true) + 3;
    $env = [
        ...test()->mysqlEnv,
        'APP_ENV' => 'testing',
        'APP_KEY' => config('app.key'),
        'LOG_CHANNEL' => 'stderr',
        // Kunci wali (F069) hanya terbagi antarproses bila store-nya `database`; phpunit memaksa `array`.
        'CACHE_STORE' => 'database',
        'F053_PRIVATE_ROOT' => Storage::disk('private')->path(''),
    ];

    $processes = array_map(fn (Applicant $applicant) => Process::env($env)->timeout(120)->start([
        PHP_BINARY, base_path('tests/Fixtures/f053-reregister.php'),
        (string) $applicant->id, (string) test()->f->user->id, sprintf('%.6f', $startAt), (string) $holdMs, now()->toIso8601ZuluString('microsecond'),
    ]), $applicants);

    return array_map(function ($process): array {
        $result = $process->wait();
        $line = Str::of($result->output())->trim()->explode("\n")->last();

        expect($result->successful())->toBeTrue($result->errorOutput())
            ->and(json_validate((string) $line))->toBeTrue("Keluaran proses anak bukan JSON: {$result->output()} {$result->errorOutput()}");

        return json_decode((string) $line, true);
    }, $processes);
}

/**
 * Pastikan hasil konversi `$applicant` utuh dan tunggal: satu santri, semua tagihan/keringanan miliknya, dokumen
 * disalin sekali (termasuk file), satu kaitan wali, dan satu activity konversi/pemindahan tagihan.
 */
function f053AssertConvertedOnce(Applicant $applicant, int $overrides): Student
{
    $student = Student::withoutGlobalScopes()->withTrashed()->where('applicant_id', $applicant->id)->sole();
    $validDocumentIds = ApplicantDocument::withoutGlobalScopes()->where('applicant_id', $applicant->id)->where('status', ApplicantDocument::STATUS_VALID)->pluck('id');
    $documents = Document::withoutUnitScope()->whereIn('source_applicant_document_id', $validDocumentIds)->get();
    $converted = Activity::query()->where('log_name', 'psb')->where('event', 'converted')->where('subject_id', $applicant->id)->get();
    $transferred = Activity::query()->where('log_name', 'keuangan')->where('event', 'applicant_billing_transferred')->where('subject_id', $student->id)->get();

    expect(Invoice::withoutUnitScope()->where('applicant_id', $applicant->id)->pluck('student_id')->unique()->all())->toBe([$student->id])
        ->and(FeeOverride::query()->where('applicant_id', $applicant->id)->count())->toBe($overrides)
        ->and(FeeOverride::query()->where('applicant_id', $applicant->id)->where(fn ($query) => $query->whereNull('student_id')->orWhere('student_id', '!=', $student->id))->count())->toBe(0)
        ->and($validDocumentIds)->not->toBeEmpty()
        ->and($documents->pluck('source_applicant_document_id')->sort()->values()->all())->toBe($validDocumentIds->sort()->values()->all())
        ->and($documents->every(fn (Document $document): bool => Storage::disk('private')->exists($document->file_path)))->toBeTrue()
        ->and(DB::table('student_guardian')->where('student_id', $student->id)->count())->toBe(1)
        ->and($converted)->toHaveCount(1)
        ->and($converted->first()->properties['student_id'])->toBe($student->id)
        ->and($transferred)->toHaveCount(1);

    return $student;
}

/**
 * Tepat satu proses berhasil dan yang lain ditolak `already_converted`, tanpa error lain.
 *
 * @param  list<array<string, mixed>>  $results
 */
function f053AssertOneWinner(array $results): void
{
    $winners = array_values(array_filter($results, fn (array $result): bool => $result['ok']));
    $losers = array_values(array_filter($results, fn (array $result): bool => ! $result['ok']));

    expect($winners)->toHaveCount(1, json_encode($results))
        ->and($losers)->toHaveCount(1)
        ->and($losers[0]['error'])->toBe(ValidationException::class, json_encode($losers[0]))
        ->and($losers[0]['errors']['applicant'] ?? [])->toBe([__('pondok.psb.errors.already_converted')]);
}

/**
 * Jumlah file di disk `private` di bawah direktori dokumen santri/wali (salinan yang tertinggal ikut terhitung).
 */
function f053DocumentFiles(): int
{
    return count(Storage::disk('private')->allFiles(Document::DIRECTORY));
}

it('AC-3/AC-4: converts the same applicant once when two processes overlap inside the transaction', function () {
    $applicant = daftarUlangApplicant($this->f);
    FeeOverride::factory()->forApplicant($applicant->id)->percent(50)->between('2026-10-15', null)->create(['fee_type_id' => $this->f->spp->id]);

    $results = f053Race([$applicant, $applicant], holdMs: 1500);

    f053AssertOneWinner($results);
    f053AssertConvertedOnce($applicant, overrides: 1);

    // Pihak yang kalah selesai setelah pemenang commit: ia benar-benar menunggu kunci, bukan berjalan berurutan.
    expect(collect($results)->firstWhere('ok', false)['elapsed_ms'])->toBeGreaterThanOrEqual(1200);
    expect(f053DocumentFiles())->toBe(Document::withoutUnitScope()->count());
});

it('AC-5: converts the same applicant once across repeated simultaneous starts (smoke)', function () {
    for ($round = 1; $round <= 3; $round++) {
        $applicant = daftarUlangApplicant($this->f, ['guardian_phone' => "08123456700{$round}"]);

        f053AssertOneWinner(f053Race([$applicant, $applicant], holdMs: 0));
        f053AssertConvertedOnce($applicant, overrides: 0);
    }

    expect(Student::withoutGlobalScopes()->withTrashed()->count())->toBe(3)
        ->and(f053DocumentFiles())->toBe(Document::withoutUnitScope()->count());
});

it('AC-6: converts two applicants of the same unit and year with distinct numbers and one cohort', function (bool $cohortExists) {
    $first = daftarUlangApplicant($this->f);
    $second = daftarUlangApplicant($this->f, ['name' => 'Umar Hakim', 'guardian_name' => 'Hasan Basri', 'guardian_phone' => '081298765432']);
    $entryYear = 2027;

    expect(Cohort::query()->where('unit_id', $first->unit_id)->where('entry_year', $entryYear)->exists())->toBeFalse();

    if ($cohortExists) {
        Cohort::factory()->create(['unit_id' => $first->unit_id, 'entry_year' => $entryYear]);
    }

    $results = f053Race([$first, $second], holdMs: 1500);

    expect(array_column($results, 'ok'))->toBe([true, true], json_encode($results))
        // Proses kedua menunggu commit proses pertama (kunci baris angkatan/NIS), lalu menahan sendiri: ≈ 2 × hold.
        ->and(max(array_column($results, 'elapsed_ms')))->toBeGreaterThanOrEqual(2400);

    $students = collect([f053AssertConvertedOnce($first, overrides: 0), f053AssertConvertedOnce($second, overrides: 0)]);

    expect($students->pluck('nis')->unique())->toHaveCount(2)
        ->and($students->pluck('nis')->sort()->values()->all())->toBe(["{$entryYear}0001", "{$entryYear}0002"])
        ->and(Cohort::query()->where('unit_id', $first->unit_id)->where('entry_year', $entryYear)->count())->toBe(1)
        ->and($students->pluck('cohort_id')->unique())->toHaveCount(1)
        ->and(f053DocumentFiles())->toBe(Document::withoutUnitScope()->count());
})->with([
    'angkatan belum ada' => false,
    'angkatan sudah ada (balapan NIS otomatis)' => true,
]);

it('F069 AC-1/AC-2: creates one guardian when siblings in different units convert at once', function () {
    $sltp = daftarUlangApplicant($this->f, ['name' => 'Ahmad Fauzan', 'guardian_phone' => '081234567890']);
    $sd = daftarUlangApplicant($this->f, ['name' => 'Umar Fauzan', 'guardian_phone' => '6281234567890'], unit: 'SD');

    expect($sd->unit_id)->not->toBe($sltp->unit_id)
        ->and($sd->guardian_name)->toBe($sltp->guardian_name);

    $results = f053Race([$sltp, $sd], holdMs: 1500);

    expect(array_column($results, 'ok'))->toBe([true, true], json_encode($results))
        // AC-2: proses kedua menunggu kunci wali, lalu menahan sendiri: ≈ 2 × hold, tanpa deadlock/lock wait timeout.
        ->and(max(array_column($results, 'elapsed_ms')))->toBeGreaterThanOrEqual(2400);

    $students = collect([f053AssertConvertedOnce($sltp, overrides: 0), f053AssertConvertedOnce($sd, overrides: 0)]);
    $guardians = Guardian::query()->whereIn('phone', ['081234567890', '6281234567890', '+6281234567890'])->get();

    expect($guardians)->toHaveCount(1)
        ->and(DB::table('student_guardian')->whereIn('student_id', $students->pluck('id'))->pluck('guardian_id')->unique()->all())->toBe([$guardians->first()->id])
        ->and(Activity::query()->where('log_name', 'psb')->where('event', 'converted')->get()->pluck('properties.guardian_reused')->sort()->values()->all())->toBe([false, true]);
});
