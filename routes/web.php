<?php

use App\Http\Controllers\Dokumen\LetterVerificationController;
use App\Http\Controllers\Psb\DownloadOwnApplicantDocument;
use App\Http\Middleware\AddPrivacyHeaders;
use App\Http\Middleware\EnsureModuleIsEnabled;
use App\Livewire\Psb\ApplicationStatus;
use App\Livewire\Psb\RegistrationForm;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// PSB publik tanpa akun (F010).
Route::prefix('psb')->name('psb.')->middleware(EnsureModuleIsEnabled::class.':psb')->group(function (): void {
    Route::get('/', RegistrationForm::class)->name('register');
    Route::get('status', ApplicationStatus::class)->name('status');
    Route::get('berkas/{document}', DownloadOwnApplicantDocument::class)->whereNumber('document')->name('document');
});

// Verifikasi keaslian surat keluar tanpa login (F014). Kode divalidasi controller, bukan regex route,
// agar kode salah format tetap mendapat halaman 404 yang sama beserta header privasi.
Route::middleware([AddPrivacyHeaders::class, EnsureModuleIsEnabled::class.':dokumen', 'throttle:letter-verification'])
    ->name('dokumen.letters.')
    ->group(function (): void {
        Route::get('verifikasi-surat', [LetterVerificationController::class, 'form'])->name('verify-form');
        // `.+` agar kode berisi `/` ter-encode tetap sampai ke controller (halaman 404 yang sama).
        Route::get('verifikasi-surat/{code}', [LetterVerificationController::class, 'show'])->where('code', '.+')->name('verify');
    });
