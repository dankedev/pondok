<?php

namespace App\Http\Controllers\Dokumen;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unduh berkas dokumen dari disk `private` sebagai lampiran. Dokumen di luar
 * cakupan jenjang tidak ditemukan lewat route model binding (404); jenis
 * rahasia dan jenis pemilik dicek policy `download`. Nama unduhan tidak
 * memuat nama pemilik maupun nama asli berkas.
 */
class DownloadDocument extends Controller
{
    public function __invoke(Document $document): StreamedResponse
    {
        Gate::authorize('download', $document);

        $disk = Storage::disk(Document::DISK);
        $path = (string) $document->file_path;

        abort_if($path === '' || ! $disk->exists($path), 404);

        $name = Str::lower((string) $document->documentType?->code).'-'.$document->id.'.'.$document->fileExtension();

        return $disk->download($path, $name, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
