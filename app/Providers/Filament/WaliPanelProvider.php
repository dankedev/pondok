<?php

namespace App\Providers\Filament;

use App\Http\Controllers\PortalWali\DownloadStudentDocument;
use App\Http\Middleware\EnsureModuleIsEnabled;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Portal wali (F022): panel Filament kedua untuk peran `wali`, hanya baca.
 * Mode terang, navigasi atas, lebar konten 2xl, tanpa dashboard bawaan, widget akun,
 * maupun notifikasi database. Seluruh route panel (termasuk login) 404 saat modul mati.
 */
class WaliPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('wali')
            ->path('wali')
            ->login()
            ->passwordReset()
            ->profile()
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable()], isRequired: false)
            ->emailVerification()
            ->emailChangeVerification()
            ->darkMode(false)
            ->topNavigation()
            ->maxContentWidth(Width::TwoExtraLarge)
            ->brandName(fn (): string => config('app.name'))
            ->colors([
                'primary' => Color::Emerald,
            ])
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->discoverPages(in: app_path('Filament/Wali/Pages'), for: 'App\Filament\Wali\Pages')
            ->middleware([
                EnsureModuleIsEnabled::class.':portal-wali,404',
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
            // Unduh dokumen santri: login panel wali, email terverifikasi, saklar Dokumen; hak akses di controller.
            ->authenticatedRoutes(fn (Panel $panel) => Route::middleware([$panel->getEmailVerifiedMiddleware(), EnsureModuleIsEnabled::class.':dokumen,404'])
                ->get('documents/{document}/download', DownloadStudentDocument::class)
                ->whereNumber('document')
                ->name('dokumen.download'));
    }
}
