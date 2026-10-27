<?php

use App\Livewire\Admin\CommandPalette;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
});

it('lists actions and menu before typing', function () {
    $this->actingAs(keuanganUser('super-admin'));

    Livewire::test(CommandPalette::class)
        ->assertSee(__('pondok.navigation_search.actions'))
        ->assertSee(__('pondok.navigation_search.category'))
        ->assertSee(__('pondok.diniyah.subject.plural'));
});

it('filters the list by the typed keyword', function () {
    $this->actingAs(keuanganUser('super-admin'));

    Livewire::test(CommandPalette::class)
        ->set('search', 'zzzxyz')
        ->assertSee(__('pondok.command_palette.empty'));
});

it('asks for two characters when only one is typed', function () {
    $this->actingAs(keuanganUser('super-admin'));

    Livewire::test(CommandPalette::class)
        ->set('search', 'k')
        ->assertSee(__('pondok.command_palette.min_length'));
});

it('does not list menu items the role cannot open', function () {
    $this->actingAs(keuanganUser('musyrif'));

    Livewire::test(CommandPalette::class)
        ->assertDontSee(__('pondok.diniyah.subject.plural'));
});

it('renders the palette and its trigger on admin pages only for signed-in users', function () {
    $this->get('/admin/login')->assertOk()->assertDontSee('open-command-palette', false);

    $this->actingAs(keuanganUser('super-admin'))->get('/admin')->assertOk()->assertSee('open-command-palette', false);
});
