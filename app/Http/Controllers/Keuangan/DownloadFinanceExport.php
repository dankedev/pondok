<?php

namespace App\Http\Controllers\Keuangan;

use App\Actions\Keuangan\ExportFinanceReportCsv;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unduh CSV laporan keuangan yang disiapkan `ExportFinanceReportJob` (F068).
 * Hanya mencari di folder milik user yang login, sehingga ekspor user lain
 * atau UUID tak dikenal sama-sama 404 tanpa membocorkan keberadaan berkas.
 */
class DownloadFinanceExport extends Controller
{
    public function __invoke(string $export): StreamedResponse
    {
        $user = auth()->user();

        abort_unless($user->can('view:finance-report'), 403);

        $disk = Storage::disk(ExportFinanceReportCsv::DISK);
        $path = collect($disk->files(ExportFinanceReportCsv::DIRECTORY."/{$user->id}/{$export}"))
            ->first(fn (string $file): bool => str_ends_with($file, '.csv'));

        abort_if($path === null, 404);

        activity('keuangan')
            ->causedBy($user)
            ->event('downloaded')
            ->withProperties(['export' => $export, 'filename' => basename($path)])
            ->log(__('pondok.finance_report.activity_downloaded'));

        return $disk->download($path, basename($path), ['X-Content-Type-Options' => 'nosniff']);
    }
}
