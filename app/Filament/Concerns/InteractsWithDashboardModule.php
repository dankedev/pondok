<?php

namespace App\Filament\Concerns;

use App\Models\Setting;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Kerangka bersama widget dashboard (F018).
 *
 * - Widget hanya dirender bila user punya permission metriknya (`canView()`).
 * - Saklar modul mati, tabel modul belum ada, atau sumber metrik belum dibangun
 *   tidak menyembunyikan widget; isinya diganti state "Modul belum tersedia."
 *   tanpa menghitung metrik dan tanpa link.
 * - Link hanya dibuat bila route terdaftar dan user boleh membukanya.
 *
 * Exception tidak ditangkap: ketersediaan dijaga lewat saklar + `Schema::hasTable()`
 * agar bug query tetap terlihat.
 */
trait InteractsWithDashboardModule
{
    abstract public static function getDashboardPermission(): string;

    abstract public static function getDashboardHeading(): string;

    abstract public static function getDashboardIcon(): Heroicon;

    /**
     * Nama modul untuk saklar `modules.<nama>.enabled`; NULL untuk widget inti tanpa saklar.
     */
    public static function getDashboardModule(): ?string
    {
        return null;
    }

    /**
     * Tabel yang dibaca Action metrik widget.
     *
     * @return list<string>
     */
    public static function getRequiredTables(): array
    {
        return [];
    }

    /**
     * False untuk widget modul yang Action metriknya belum dibangun.
     */
    public static function hasMetricSource(): bool
    {
        return true;
    }

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can(static::getDashboardPermission());
    }

    /**
     * Urutan cek: saklar modul, sumber metrik, lalu tabel (berhenti di cek pertama yang gagal).
     */
    public static function isModuleAvailable(): bool
    {
        $module = static::getDashboardModule();

        if ($module !== null && ! Setting::moduleEnabled($module)) {
            return false;
        }

        if (! static::hasMetricSource()) {
            return false;
        }

        foreach (static::getRequiredTables() as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function urlIfRouteAvailable(string $routeName, bool $authorized = true, array $parameters = []): ?string
    {
        if (! $authorized || ! Route::has($routeName)) {
            return null;
        }

        return route($routeName, $parameters);
    }

    public function render(): View
    {
        if (! static::isModuleAvailable()) {
            return view('filament.widgets.module-unavailable', [
                'heading' => static::getDashboardHeading(),
                'icon' => static::getDashboardIcon(),
            ]);
        }

        return parent::render();
    }
}
