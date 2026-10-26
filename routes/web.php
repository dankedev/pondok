<?php

use App\Http\Controllers\Dokumen\LetterVerificationController;
use App\Http\Controllers\Portal\ListAlbums;
use App\Http\Controllers\Portal\ListPosts;
use App\Http\Controllers\Portal\ListVideos;
use App\Http\Controllers\Portal\ShowAlbum;
use App\Http\Controllers\Portal\ShowHome;
use App\Http\Controllers\Portal\ShowPost;
use App\Http\Controllers\Portal\ShowPsbInfo;
use App\Http\Controllers\Portal\ShowRobots;
use App\Http\Controllers\Portal\ShowSitemap;
use App\Http\Controllers\Portal\ShowStaticPage;
use App\Http\Controllers\PortalWali\DownloadStudentDocument;
use App\Http\Controllers\PortalWali\EmailVerificationController;
use App\Http\Controllers\PortalWali\Logout as PortalWaliLogout;
use App\Http\Controllers\PortalWali\ShowDiniyahReport as PortalWaliDiniyahReport;
use App\Http\Controllers\PortalWali\ShowHome as PortalWaliHome;
use App\Http\Controllers\PortalWali\ShowInvoice as PortalWaliInvoice;
use App\Http\Controllers\PortalWali\ShowStudent as PortalWaliStudent;
use App\Http\Controllers\Psb\DownloadOwnApplicantDocument;
use App\Http\Middleware\AddPrivacyHeaders;
use App\Http\Middleware\EnsureModuleIsEnabled;
use App\Http\Middleware\EnsurePortalWaliUser;
use App\Http\Middleware\ForcePortalRootUrl;
use App\Http\Middleware\SetPortalCacheHeaders;
use App\Http\Middleware\SetPortalSecurityHeaders;
use App\Livewire\PortalWali\ForgotPassword as PortalWaliForgotPassword;
use App\Livewire\PortalWali\Login as PortalWaliLogin;
use App\Livewire\PortalWali\ResetPassword as PortalWaliResetPassword;
use App\Livewire\Psb\ApplicationStatus;
use App\Livewire\Psb\RegistrationForm;
use App\Models\StaticPage;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// PSB publik tanpa akun (F010).
Route::prefix('psb')->name('psb.')->middleware(EnsureModuleIsEnabled::class.':psb')->group(function (): void {
    Route::get('/', RegistrationForm::class)->name('register');
    Route::get('status', ApplicationStatus::class)->name('status');
    Route::get('berkas/{document}', DownloadOwnApplicantDocument::class)->whereNumber('document')->name('document');
});

// Portal wali (F079): Blade + Livewire, guard `web`. Seluruh route (termasuk masuk) 404 saat modul mati;
// saklar dicek sebelum autentikasi (prioritas di bootstrap/app.php), jadi tamu pun mendapat 404.
Route::prefix('wali')
    ->name('portal-wali.')
    ->middleware([EnsureModuleIsEnabled::class.':portal-wali,404', AddPrivacyHeaders::class])
    ->group(function (): void {
        Route::middleware('guest')->group(function (): void {
            Route::get('masuk', PortalWaliLogin::class)->name('login');
            Route::get('lupa-password', PortalWaliForgotPassword::class)->name('password.request');
        });

        // Tanpa `guest`: tautan atur password dari email tetap terbuka walau peramban sedang masuk akun lain.
        Route::get('atur-password/{token}', PortalWaliResetPassword::class)->name('password.reset');

        Route::middleware(['auth', 'auth.session', EnsurePortalWaliUser::class])->group(function (): void {
            Route::get('email/verifikasi', [EmailVerificationController::class, 'notice'])->name('verification.notice');
            Route::get('email/verifikasi/{id}/{hash}', [EmailVerificationController::class, 'verify'])
                ->middleware(['signed', 'throttle:6,1'])
                ->whereNumber('id')
                ->name('verification.verify');
            Route::post('email/verifikasi', [EmailVerificationController::class, 'send'])->middleware('throttle:6,1')->name('verification.send');
            Route::post('keluar', PortalWaliLogout::class)->name('logout');

            Route::middleware('verified:portal-wali.verification.notice')->group(function (): void {
                Route::get('/', PortalWaliHome::class)->name('home');
                Route::get('santri/{student}', PortalWaliStudent::class)->whereNumber('student')->name('students.show');
                Route::get('santri/{student}/rapor/{year}/{semester}', PortalWaliDiniyahReport::class)
                    ->middleware(EnsureModuleIsEnabled::class.':akademik,404')
                    ->whereNumber(['student', 'year', 'semester'])
                    ->name('diniyah-report.show');
                Route::get('tagihan/{invoice}', PortalWaliInvoice::class)
                    ->middleware(EnsureModuleIsEnabled::class.':keuangan,404')
                    ->whereNumber('invoice')
                    ->name('invoices.show');
                Route::get('dokumen/{document}/unduh', DownloadStudentDocument::class)
                    ->middleware(EnsureModuleIsEnabled::class.':dokumen,404')
                    ->whereNumber('document')
                    ->name('documents.download');
            });
        });
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

// Portal publik (F017): stateless (tanpa sesi, cookie, CSRF) agar aman di-cache publik.
// Daftar `withoutMiddleware` mengikuti isi grup `web` Laravel 13. Route `/{page}` paling akhir.
$statelessWeb = [EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, ValidateCsrfToken::class];
$portalHeaders = [ForcePortalRootUrl::class, SetPortalSecurityHeaders::class, SetPortalCacheHeaders::class];

// Info PSB publik (F038): stateless seperti portal, tetapi bernama `psb.info` (di luar grup `portal.`) dan
// mengikuti saklar psb, bukan konten. Didaftarkan sebelum route `/{page}`.
Route::get('info-psb', ShowPsbInfo::class)
    ->withoutMiddleware($statelessWeb)
    ->middleware([...$portalHeaders, EnsureModuleIsEnabled::class.':psb,404'])
    ->name('psb.info');

Route::name('portal.')
    ->withoutMiddleware($statelessWeb)
    ->middleware($portalHeaders)
    ->group(function (): void {
        Route::get('/', ShowHome::class)->name('home');
        Route::get('sitemap.xml', ShowSitemap::class)->name('sitemap');
        Route::get('robots.txt', ShowRobots::class)->name('robots');

        Route::middleware(EnsureModuleIsEnabled::class.':konten,404')->group(function (): void {
            $slug = '[a-z0-9]+(?:-[a-z0-9]+)*';

            Route::get('berita', ListPosts::class)->name('posts.index');
            Route::get('berita/{slug}', ShowPost::class)->where('slug', $slug)->name('posts.show');
            Route::get('galeri', ListAlbums::class)->name('albums.index');
            Route::get('galeri/{slug}', ShowAlbum::class)->where('slug', $slug)->name('albums.show');
            Route::get('video', ListVideos::class)->name('videos.index');
            // Halaman statis bebas (F037): slug divalidasi `ResolveStaticPageSlug` agar tidak bentrok dengan route lain.
            Route::get('{page}', ShowStaticPage::class)->where('page', StaticPage::SLUG_PATTERN)->name('pages.show');
        });
    });
