<?php

use App\Http\Controllers\Psb\DownloadOwnApplicantDocument;
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
