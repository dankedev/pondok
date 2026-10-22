<?php

use App\Actions\Core\CreateBackup;
use App\Actions\Core\DumpDatabase;
use App\Actions\Core\UpdateDataRetentionSettings;
use App\Actions\Dokumen\RedactExpiredLetterSnapshots;
use App\Filament\Pages\Settings\DataRetention;
use App\Models\Setting;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;

/*
| F042 VERIFY: celah di luar test RED (isi dump yang bermusuhan, cakupan arsip, setting rusak, akses).
*/

beforeEach(fn () => backupIsolateStorage());

it('VERIFY: dump round-trips hostile strings, big money, floats, booleans and json', function () {
    Schema::create('verify_dump', function ($table) {
        $table->id();
        $table->text('body')->nullable();
        $table->unsignedBigInteger('amount');
        $table->double('ratio')->nullable();
        $table->boolean('flag');
        $table->json('meta')->nullable();
        $table->date('on')->nullable();
    });

    $rows = [
        ['body' => "x'); DELETE FROM students; --", 'amount' => 9007199254740993, 'ratio' => 0.1, 'flag' => true, 'meta' => '{"a":"INSERT INTO t VALUES (1);"}', 'on' => '2026-02-28'],
        ['body' => "INSERT INTO verify_dump VALUES (1);\nDELETE FROM verify_dump;\r\n", 'amount' => 0, 'ratio' => 1.0E-7, 'flag' => false, 'meta' => null, 'on' => null],
        ['body' => "tab\there", 'amount' => 123, 'ratio' => 12345678.9012345, 'flag' => true, 'meta' => '[]', 'on' => '2026-01-01'],
    ];
    DB::table('verify_dump')->insert($rows);
    $before = DB::table('verify_dump')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

    $path = tempnam(sys_get_temp_dir(), 'f042-verify-');

    try {
        app(DumpDatabase::class)->handle($path);

        DB::table('verify_dump')->delete();
        DB::table('verify_dump')->insert(['body' => 'sisa', 'amount' => 1, 'flag' => false]);
        DB::unprepared((string) file_get_contents($path));
    } finally {
        @unlink($path);
    }

    $after = DB::table('verify_dump')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

    expect($after)->toBe($before);
});

it('VERIFY: dump keeps a NUL byte inside a text value', function () {
    Schema::create('verify_nul', function ($table) {
        $table->id();
        $table->text('body');
    });
    DB::table('verify_nul')->insert(['body' => "a\0b"]);
    $path = tempnam(sys_get_temp_dir(), 'f042-verify-');

    try {
        app(DumpDatabase::class)->handle($path);
        DB::table('verify_nul')->delete();
        DB::unprepared((string) file_get_contents($path));
    } finally {
        @unlink($path);
    }

    expect(DB::table('verify_nul')->value('body'))->toBe("a\0b");
});

it('VERIFY: backup includes root-level files and folders that only share a prefix with skipped ones', function () {
    kontenBoot();
    Storage::disk('private')->put('catatan-root.txt', 'a');
    Storage::disk('private')->put('finance-exports-lama/x.csv', 'b');
    Storage::disk('private')->put('documents/backups/nested.pdf', 'c');
    Storage::disk('private')->put('finance-exports/skip.csv', 'd');
    Storage::disk('public')->put('root.jpg', 'e');

    $path = app(CreateBackup::class)->handle();

    $zip = new ZipArchive;
    $zip->open(Storage::disk('private')->path($path));
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = (string) $zip->getNameIndex($i);
    }
    $zip->close();

    expect($names)->toContain('private/catatan-root.txt', 'private/finance-exports-lama/x.csv', 'private/documents/backups/nested.pdf', 'public/root.jpg')
        ->not->toContain('private/finance-exports/skip.csv');
    expect(File::isDirectory(storage_path('app/backup-tmp')) ? File::allFiles(storage_path('app/backup-tmp')) : [])->toBe([]);
});

it('VERIFY: a broken retention setting never redacts anything', function (mixed $value) {
    $f = suratFixture();
    $letter = suratIssue($f);
    DB::table('issued_letters')->where('id', $letter->id)->update(['issued_on' => '2000-01-01']);
    $before = $letter->fresh()->snapshot;
    Setting::set('retention.letter_snapshot_years', $value);

    expect(app(RedactExpiredLetterSnapshots::class)->handle())->toBe(0)
        ->and($letter->fresh()->snapshot)->toBe($before)
        ->and($letter->fresh()->snapshot_redacted_at)->toBeNull();
})->with(['abc', 0, -3, '', 1000000]);

it('VERIFY: redaction survives a snapshot without a student block', function () {
    $f = suratFixture();
    $letter = suratIssue($f);
    DB::table('issued_letters')->where('id', $letter->id)->update(['issued_on' => '2000-01-01', 'snapshot' => json_encode(['school' => ['name' => 'X']])]);
    Setting::set('retention.letter_snapshot_years', 1);

    expect(app(RedactExpiredLetterSnapshots::class)->handle())->toBe(1)
        ->and($letter->fresh()->snapshot['school'])->toBe(['name' => 'X']);
});

it('VERIFY: the settings action refuses non super-admins even when called directly', function (string $role) {
    kontenBoot();
    $user = kontenUser($role);

    expect(fn () => app(UpdateDataRetentionSettings::class)->handle(['backup_enabled' => true, 'letter_snapshot_years' => 1], $user))
        ->toThrow(AuthorizationException::class);

    expect(Setting::get('backup.enabled', false))->toBeFalse()
        ->and(Setting::get('retention.letter_snapshot_years'))->toBeNull()
        ->and(Activity::query()->where('log_name', 'sistem')->count())->toBe(0);
})->with(['admin-sekolah', 'bendahara']);

it('VERIFY: the Livewire page cannot be mounted by a non super-admin', function () {
    kontenBoot();
    $this->actingAs(kontenUser('admin-sekolah'));

    Livewire::test(DataRetention::class)->assertForbidden();
});

it('VERIFY: non-integer retention years are rejected by the action', function (mixed $years) {
    kontenBoot();

    expect(fn () => app(UpdateDataRetentionSettings::class)->handle(['backup_enabled' => false, 'letter_snapshot_years' => $years], kontenUser('super-admin')))
        ->toThrow(ValidationException::class);
})->with(['abc', 1.5, '2 tahun', -1]);

it('VERIFY: the data-retention page permission is not granted to any non super-admin role', function () {
    kontenBoot();

    $holders = Permission::query()->where('name', 'view:data-retention')->first()?->roles()->pluck('name')->all() ?? [];

    expect($holders)->toBe([]);
});
