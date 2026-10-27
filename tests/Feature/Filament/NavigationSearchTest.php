<?php

use App\Filament\GlobalSearch\NavigationAwareGlobalSearchProvider;
use App\Models\Setting;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
});

function menuSearch(string $role, string $query): array
{
    test()->actingAs(keuanganUser($role));

    $categories = (new NavigationAwareGlobalSearchProvider)->getResults($query)->getCategories();

    return collect($categories)
        ->except(__('pondok.navigation_search.actions'))
        ->flatten(1)
        ->map(fn ($result): string => (string) $result->title)
        ->all();
}

it('finds menu items by label for a user allowed to open them', function () {
    expect(menuSearch('bendahara', 'jenis biaya'))->not->toBeEmpty();
});

it('finds menu items by group name', function () {
    expect(menuSearch('super-admin', 'keuangan'))->not->toBeEmpty();
});

it('hides menu items the role cannot open', function () {
    expect(menuSearch('musyrif', 'jenis biaya'))->toBeEmpty();
});

it('hides menu items of a disabled module', function () {
    Setting::set('modules.keuangan.enabled', false);

    expect(menuSearch('super-admin', 'jenis biaya'))->toBeEmpty();
});

it('skips the menu category for a one-character query', function () {
    expect(menuSearch('super-admin', 'k'))->toBeEmpty();
});

function actionSearch(string $role, string $query): array
{
    test()->actingAs(keuanganUser($role));

    $categories = (new NavigationAwareGlobalSearchProvider)->getResults($query)->getCategories();

    return collect($categories[__('pondok.navigation_search.actions')] ?? [])
        ->map(fn ($result): string => (string) $result->title)
        ->all();
}

it('offers create actions for resources the user may create', function () {
    expect(actionSearch('super-admin', 'tambah'))->toContain(
        __('pondok.navigation_search.create', ['model' => Str::lower(__('pondok.diniyah.subject.label'))])
    );
});

it('hides create actions the role cannot perform', function () {
    expect(actionSearch('musyrif', 'tambah'))->not->toContain(
        __('pondok.navigation_search.create', ['model' => Str::lower(__('pondok.diniyah.subject.label'))])
    );
});

it('hides create actions of a disabled module', function () {
    Setting::set('modules.akademik.enabled', false);

    expect(actionSearch('super-admin', 'tambah'))->not->toContain(
        __('pondok.navigation_search.create', ['model' => Str::lower(__('pondok.diniyah.subject.label'))])
    );
});

it('skips the action category for a one-character query', function () {
    expect(actionSearch('super-admin', 't'))->toBeEmpty();
});
