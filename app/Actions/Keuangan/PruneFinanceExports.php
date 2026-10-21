<?php

namespace App\Actions\Keuangan;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Menghapus CSV laporan keuangan (F068) di `finance-exports` yang berumur lebih
 * dari `RETENTION_HOURS`, beserta folder `{uuid}`-nya yang menjadi kosong.
 * Berkas titik (`.gitkeep`) diabaikan. Satu berkas yang gagal dihapus dicatat
 * lalu dilewati.
 */
class PruneFinanceExports
{
    public const RETENTION_HOURS = 24;

    /**
     * @return int jumlah berkas yang dihapus
     */
    public function handle(?CarbonInterface $now = null): int
    {
        $disk = Storage::disk(ExportFinanceReportCsv::DISK);
        $cutoff = ($now ?? now())->copy()->subHours(self::RETENTION_HOURS)->getTimestamp();

        $expired = collect($disk->allFiles(ExportFinanceReportCsv::DIRECTORY))
            ->reject(fn (string $path): bool => str_starts_with(basename($path), '.'))
            ->filter(fn (string $path): bool => $disk->lastModified($path) < $cutoff);

        $deleted = 0;

        foreach ($expired as $path) {
            if (! $disk->delete($path)) {
                Log::warning('CSV laporan keuangan gagal dihapus', ['path' => $path]);

                continue;
            }

            $deleted++;

            $folder = dirname($path);

            if ($disk->allFiles($folder) === [] && $disk->directories($folder) === []) {
                $disk->deleteDirectory($folder);
            }
        }

        Log::info('Pembersihan CSV laporan keuangan selesai', ['deleted' => $deleted]);

        return $deleted;
    }
}
