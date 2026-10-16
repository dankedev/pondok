<?php

namespace App\Filament\Clusters\InstitutionProfile;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Navigation\NavigationItem;
use Filament\Support\Icons\Heroicon;

/**
 * Profil lembaga (F016/F036): identitas, kontak & lokasi, beranda, dan footer portal
 * di `settings`. Akses lewat permission page `view:school-profile` (super-admin,
 * admin-sekolah, admin-konten); tidak terikat saklar modul.
 */
class InstitutionProfileCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'institution-profile';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('view:school-profile');
    }

    /**
     * Item navigasi disimpan di instance panel; cek ulang akses saat dirender.
     *
     * @return array<NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        return array_map(
            fn (NavigationItem $item): NavigationItem => $item->visible(fn (): bool => static::canAccess()),
            parent::getNavigationItems(),
        );
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.pengaturan');
    }

    public static function getNavigationLabel(): string
    {
        return __('pondok.school_profile.title');
    }

    public static function getClusterBreadcrumb(): ?string
    {
        return __('pondok.school_profile.title');
    }
}
