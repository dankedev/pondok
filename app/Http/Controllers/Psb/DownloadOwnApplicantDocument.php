<?php

namespace App\Http\Controllers\Psb;

use App\Http\Controllers\Controller;
use App\Livewire\Psb\ApplicationStatus;
use App\Models\ApplicantDocument;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unduh berkas milik pendaftar dari halaman status publik. Dimuat tanpa
 * global scope (admin yang login di browser sama tidak berpengaruh). Semua
 * kasus tidak berhak dijawab 404 seragam: id tidak ada, tanpa sesi, sesi
 * pendaftar lain, token sudah diatur ulang, pendaftar terhapus, file hilang.
 */
class DownloadOwnApplicantDocument extends Controller
{
    public function __invoke(int $document): StreamedResponse
    {
        $applicantDocument = ApplicantDocument::withoutGlobalScopes()->find($document);
        $applicant = ApplicationStatus::sessionApplicant();

        abort_if($applicantDocument === null || $applicant === null || $applicantDocument->applicant_id !== $applicant->id, 404);

        return static::respond($applicantDocument);
    }

    public static function respond(ApplicantDocument $document): StreamedResponse
    {
        $disk = Storage::disk(ApplicantDocument::DISK);

        abort_if(blank($document->file_path) || ! $disk->exists($document->file_path), 404);

        return $disk->response($document->file_path, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
