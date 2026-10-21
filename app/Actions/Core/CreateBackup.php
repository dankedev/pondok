<?php

namespace App\Actions\Core;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Membuat arsip backup `backups/pondok-YYYYmmdd-His.zip` di disk `private`:
 * dump data (`database.sql`), `manifest.json`, berkas disk `private` dan
 * `public`. Arsip dibangun di folder sementara dan baru dipindah setelah
 * lengkap, sehingga kegagalan tidak meninggalkan arsip setengah jadi dan
 * tidak memicu rotasi arsip lama.
 */
class CreateBackup
{
    public const KEEP = 3;

    public const DISK = 'private';

    public const DIRECTORY = 'backups';

    /**
     * Folder disk `private` yang tidak ikut: arsip backup sendiri, CSV ekspor
     * sementara, dan unggahan sementara Livewire.
     */
    public const SKIPPED_PRIVATE_DIRECTORIES = ['backups', 'finance-exports', 'livewire-tmp'];

    public function __construct(private DumpDatabase $dump) {}

    /**
     * @return string path arsip relatif terhadap disk `private`
     */
    public function handle(): string
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            throw new RuntimeException(__('pondok.backup.pgsql_unsupported'));
        }

        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException(__('pondok.backup.zip_unavailable'));
        }

        $temp = storage_path('app/backup-tmp/'.Str::random(16));
        File::ensureDirectoryExists($temp);

        try {
            $rows = $this->dump->handle("{$temp}/database.sql");
            $skippedDisks = [];

            $zip = new ZipArchive;

            if ($zip->open("{$temp}/backup.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException(__('pondok.backup.zip_failed'));
            }

            $zip->addFile("{$temp}/database.sql", 'database.sql');

            foreach (['private' => self::SKIPPED_PRIVATE_DIRECTORIES, 'public' => []] as $name => $skipped) {
                if (config("filesystems.disks.{$name}.driver") !== 'local') {
                    $skippedDisks[] = $name;

                    continue;
                }

                $this->addDisk($zip, $name, $skipped);
            }

            $zip->addFromString('manifest.json', (string) json_encode([
                'created_at' => now()->toIso8601String(),
                'driver' => $driver,
                'last_migration' => DB::table(config('database.migrations.table', 'migrations'))->orderByDesc('id')->value('migration'),
                'rows' => $rows,
                'skipped_disks' => $skippedDisks,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            // `addFile` membaca berkas saat `close`, jadi isi tidak ditampung di memori.
            if (! $zip->close()) {
                throw new RuntimeException(__('pondok.backup.zip_failed'));
            }

            $disk = Storage::disk(self::DISK);
            $path = self::DIRECTORY.'/pondok-'.now()->format('Ymd-His').'.zip';

            $disk->makeDirectory(self::DIRECTORY);
            File::move("{$temp}/backup.zip", $disk->path($path));
        } finally {
            File::deleteDirectory($temp);
        }

        $this->rotate($disk);

        activity('sistem')
            ->event('backup')
            ->withProperties(['file' => $path, 'size' => $disk->size($path)])
            ->log(__('pondok.backup.activity_created'));

        return $path;
    }

    /**
     * @param  list<string>  $skippedDirectories
     */
    private function addDisk(ZipArchive $zip, string $name, array $skippedDirectories): void
    {
        $disk = Storage::disk($name);
        $files = $disk->files();

        foreach ($disk->directories() as $directory) {
            if (! in_array($directory, $skippedDirectories, true)) {
                array_push($files, ...$disk->allFiles($directory));
            }
        }

        foreach ($files as $file) {
            $zip->addFile($disk->path($file), "{$name}/{$file}");
        }
    }

    /**
     * Sisakan `KEEP` arsip terbaru; nama arsip memuat waktu sehingga urutan
     * nama sama dengan urutan waktu. Berkas lain di folder tidak disentuh.
     */
    private function rotate(Filesystem $disk): void
    {
        collect($disk->files(self::DIRECTORY))
            ->filter(fn (string $file): bool => (bool) preg_match('#^'.self::DIRECTORY.'/pondok-\d{8}-\d{6}\.zip$#', $file))
            ->sortDesc()
            ->slice(self::KEEP)
            ->each(fn (string $file): bool => $disk->delete($file));
    }
}
