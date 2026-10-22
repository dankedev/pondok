<?php

namespace App\Filament\Wali\Pages;

use App\Enums\Module;
use App\Models\Setting;
use App\Models\Student;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

/**
 * Beranda portal wali (F022): daftar santri yang terhubung dengan akun wali login.
 * Data hanya lewat `User::portalStudents()`.
 */
class Dashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?int $navigationSort = -2;

    protected string $view = 'filament.wali.pages.dashboard';

    public static function getModule(): string
    {
        return Module::PortalWali->value;
    }

    public static function canAccess(): bool
    {
        return Setting::moduleEnabled(static::getModule());
    }

    public static function getRoutePath(Panel $panel): string
    {
        return '/';
    }

    public static function getNavigationLabel(): string
    {
        return __('pondok.portal_wali.home');
    }

    public function getTitle(): string
    {
        return __('pondok.portal_wali.home');
    }

    /**
     * @return Collection<int, Student>
     */
    public function getStudents(): Collection
    {
        return auth()->user()->portalStudents()->orderBy('name')->get(['students.id', 'name', 'nis']);
    }
}
