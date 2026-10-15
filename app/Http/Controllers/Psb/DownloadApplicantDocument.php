<?php

namespace App\Http\Controllers\Psb;

use App\Http\Controllers\Controller;
use App\Models\ApplicantDocument;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unduh berkas pendaftar untuk panitia (panel). Berkas di luar cakupan
 * jenjang tidak ditemukan lewat route model binding (404); akses lewat
 * permission `view:applicant`.
 */
class DownloadApplicantDocument extends Controller
{
    public function __invoke(ApplicantDocument $applicantDocument): StreamedResponse
    {
        Gate::authorize('view', $applicantDocument->applicant);

        return DownloadOwnApplicantDocument::respond($applicantDocument);
    }
}
