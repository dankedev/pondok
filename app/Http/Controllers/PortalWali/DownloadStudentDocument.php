<?php

namespace App\Http\Controllers\PortalWali;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dokumen\DownloadDocument;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unduh dokumen santri dari portal wali (F022). Hak akses hanya kepemilikan lewat
 * `User::portalDocument()` (current, non-rahasia, santri milik wali); selain itu 404.
 */
class DownloadStudentDocument extends Controller
{
    public function __invoke(Request $request, int $document): StreamedResponse
    {
        return DownloadDocument::respond($request->user()->portalDocument($document));
    }
}
