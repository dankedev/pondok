<?php

use App\Actions\Core\CreateBackup;
use App\Actions\Core\DumpDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/*
| F042 AC-1, AC-3, AC-4: backup harian (PHP murni) ke disk private.
*/

beforeEach(function () {
    kontenBoot();
    backupIsolateStorage();
    File::deleteDirectory(storage_path('app/backup-tmp'));
});

/**
 * Nama entri di dalam zip pada disk private.
 *
 * @return list<string>
 */
function backupEntries(string $path): array
{
    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('private')->path($path)))->toBeTrue();

    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = (string) $zip->getNameIndex($i);
    }
    $zip->close();

    return $names;
}

function backupEntry(string $path, string $entry): string
{
    $zip = new ZipArchive;
    $zip->open(Storage::disk('private')->path($path));
    $content = (string) $zip->getFromName($entry);
    $zip->close();

    return $content;
}

/**
 * Arsip lama dengan nama dan waktu ubah berurutan: `$day` 1 paling tua.
 */
function backupOldArchive(int $day): string
{
    $path = sprintf('backups/pondok-202601%02d-000000.zip', $day);
    Storage::disk('private')->put($path, "arsip-{$day}");
    touch(Storage::disk('private')->path($path), strtotime(sprintf('2026-01-%02d 00:00:00', $day)));

    return $path;
}

/**
 * @return list<string>
 */
function backupArchives(): array
{
    return collect(Storage::disk('private')->files('backups'))
        ->filter(fn (string $file): bool => (bool) preg_match('#^backups/pondok-.*\.zip$#', $file))
        ->sort()->values()->all();
}

it('AC-1: the command writes one zip with the dump, manifest and the private and public files only', function () {
    Storage::disk('private')->put('documents/x.pdf', 'pdf');
    Storage::disk('private')->put('finance-exports/a.csv', 'csv');
    Storage::disk('private')->put('livewire-tmp/t', 'tmp');
    Storage::disk('public')->put('posts/p.jpg', 'jpg');

    $exit = Artisan::call('pondok:backup');
    $archives = backupArchives();

    expect($exit)->toBe(0)
        ->and($archives)->toHaveCount(1)
        ->and($archives[0])->toMatch('#^backups/pondok-\d{8}-\d{6}\.zip$#');

    $entries = backupEntries($archives[0]);

    expect($entries)->toContain('database.sql', 'manifest.json', 'private/documents/x.pdf', 'public/posts/p.jpg');

    foreach ($entries as $entry) {
        expect($entry)->not->toStartWith('private/finance-exports')
            ->not->toStartWith('private/livewire-tmp')
            ->not->toStartWith('private/backups');
    }

    expect(backupEntry($archives[0], 'private/documents/x.pdf'))->toBe('pdf')
        ->and(backupEntry($archives[0], 'database.sql'))->toContain('INSERT INTO')
        ->and(File::isDirectory(storage_path('app/backup-tmp')) ? File::allFiles(storage_path('app/backup-tmp')) : [])->toBe([]);
});

it('AC-1: the manifest records the creation time, driver, last migration, row counts and skipped disks', function () {
    $path = app(CreateBackup::class)->handle();
    $manifest = json_decode(backupEntry($path, 'manifest.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest)->toHaveKeys(['created_at', 'driver', 'last_migration', 'rows', 'skipped_disks'])
        ->and($manifest['driver'])->toBe('sqlite')
        ->and($manifest['last_migration'])->toBe(DB::table('migrations')->orderByDesc('id')->value('migration'))
        ->and($manifest['rows']['users'])->toBe(DB::table('users')->count())
        ->and($manifest['skipped_disks'])->toBeArray();
});

it('AC-3: keeps the three newest archives and leaves other files in backups', function () {
    foreach ([1, 2, 3, 4] as $day) {
        backupOldArchive($day);
    }
    Storage::disk('private')->put('backups/catatan.txt', 'jangan dihapus');

    expect(CreateBackup::KEEP)->toBe(3);

    $new = app(CreateBackup::class)->handle();

    expect(backupArchives())->toBe([
        'backups/pondok-20260103-000000.zip',
        'backups/pondok-20260104-000000.zip',
        $new,
    ])->and(Storage::disk('private')->exists('backups/catatan.txt'))->toBeTrue();
});

it('AC-4: a failing dump exits 1 with a translated message, keeps old archives and logs no backup activity', function () {
    foreach ([1, 2, 3, 4, 5] as $day) {
        backupOldArchive($day);
    }
    Log::spy();
    $this->mock(DumpDatabase::class, fn ($mock) => $mock->shouldReceive('handle')->andThrow(new RuntimeException('disk penuh')));

    $exit = Artisan::call('pondok:backup');

    expect($exit)->toBe(1)
        ->and(trim(Artisan::output()))->not->toBe('')->not->toMatch('/pondok\.backup\./')
        ->and(backupArchives())->toHaveCount(5)
        ->and(Activity::query()->where('event', 'backup')->count())->toBe(0)
        ->and(File::isDirectory(storage_path('app/backup-tmp')) ? File::allFiles(storage_path('app/backup-tmp')) : [])->toBe([]);

    Log::shouldHaveReceived('error')->once();
});

it('AC-4: refuses Postgres with a translated message and writes nothing', function () {
    backupOldArchive(1);
    retentionPretendPgsql();

    $exit = Artisan::call('pondok:backup');

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain(__('pondok.backup.pgsql_unsupported'))
        ->and(backupArchives())->toBe(['backups/pondok-20260101-000000.zip'])
        ->and(Activity::query()->where('event', 'backup')->count())->toBe(0);
});

it('AC-4: a successful backup logs one sistem activity with the file and size', function () {
    $path = app(CreateBackup::class)->handle();
    $activity = Activity::query()->where('log_name', 'sistem')->where('event', 'backup')->sole();

    expect($activity->properties['file'])->toContain(basename($path))
        ->and($activity->properties['size'])->toBe(Storage::disk('private')->size($path));
});

it('AC-4: the backup language keys have text', function () {
    expectTranslatedKeys(['pondok.backup.pgsql_unsupported']);
});
