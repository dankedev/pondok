<?php

namespace App\Providers\Filament;

use App\Http\Controllers\Dokumen\DownloadDocument;
use App\Http\Controllers\Dokumen\PrintIssuedLetter;
use App\Http\Controllers\Keuangan\DownloadPaymentProof;
use App\Http\Controllers\Keuangan\ShowPaymentReceipt;
use App\Http\Controllers\Psb\DownloadApplicantDocument;
use App\Http\Middleware\EnsureModuleIsEnabled;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Events\ServingFilament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationManager;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    /**
     * Navigasi Filament (binding `scoped`) disusun ulang di setiap request panel,
     * sehingga menu yang disusun untuk user lain dalam proses yang sama (test,
     * worker berumur panjang) tidak ikut tampil.
     */
    public function boot(): void
    {
        Event::listen(ServingFilament::class, fn () => $this->app->forgetInstance(NavigationManager::class));
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->passwordReset()
            ->profile()
            ->brandName(fn (): string => config('app.name'))
            ->colors([
                'primary' => Color::Emerald,
            ])
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->navigationGroups(array_values(__('pondok.navigation')))
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\Filament\Clusters')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make()
                    ->navigationGroup(fn (): string => __('pondok.navigation.access')),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            // Kuitansi cetak dan unduh bukti transfer: login panel, saklar modul
            // (sebelum binding), cakupan jenjang lewat binding, lalu policy di controller.
            ->authenticatedRoutes(function (): void {
                Route::middleware(EnsureModuleIsEnabled::class.':keuangan')->group(function (): void {
                    Route::get('receipts/{payment}', ShowPaymentReceipt::class)->name('keuangan.receipt');
                    Route::get('payment-proofs/{payment}', DownloadPaymentProof::class)->name('keuangan.payment-proof');
                });
                Route::middleware(EnsureModuleIsEnabled::class.':psb')->group(function (): void {
                    Route::get('applicant-documents/{applicantDocument}', DownloadApplicantDocument::class)->name('psb.applicant-document');
                });
                Route::middleware(EnsureModuleIsEnabled::class.':dokumen')->group(function (): void {
                    Route::get('documents/{document}/download', DownloadDocument::class)->whereNumber('document')->name('dokumen.download');
                    Route::get('letters/{issuedLetter}/print', PrintIssuedLetter::class)->whereNumber('issuedLetter')->name('dokumen.letters.print');
                });
            });
    }
}
