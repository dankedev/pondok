<?php

use App\Enums\Module;
use App\Filament\Pages\Settings\Modules;
use App\Models\Setting;
use Livewire\Livewire;

beforeEach(function () {
    kontenBoot();
    $this->url = '/admin/settings/modules';
});

it('AC-7: shows every module with its label, description and requirements to the super-admin', function () {
    $html = bayarHtmlText($this->actingAs(kontenUser('super-admin'))->get($this->url)->assertOk()->getContent());

    foreach (Module::cases() as $module) {
        expect($html)->toContain($module->label())->toContain($module->description());
    }

    expect($html)->toContain('Membutuhkan')
        ->toContain('Modul inti')
        ->and(Modules::getNavigationItems())->not->toBeEmpty();
});

it('AC-7: forbids other roles and hides the navigation item', function (string $role) {
    $this->actingAs(kontenUser($role))->get($this->url)->assertForbidden();

    expect(Modules::getNavigationItems())->toBeEmpty();
})->with(['admin-sekolah', 'bendahara']);

it('AC-8: toggles a module from the page and reports success', function () {
    $this->actingAs(kontenUser('super-admin'));

    Livewire::test(Modules::class)->callAction('toggle-konten');

    expect(Module::Konten->isEnabled())->toBeFalse()
        ->and(asramaNotifications()->pluck('status')->all())->toContain('success');
});

it('AC-8: reports the action error as a danger notification and keeps the setting', function () {
    $this->actingAs(kontenUser('super-admin'));

    Livewire::test(Modules::class)->callAction('toggle-keuangan');

    $notifications = asramaNotifications();

    expect(Module::Keuangan->isEnabled())->toBeTrue()
        ->and($notifications->pluck('status')->all())->toContain('danger')
        ->and($notifications->map(fn (array $n): string => ($n['title'] ?? '').' '.($n['body'] ?? ''))->implode(' '))->toContain('PSB');
});

it('AC-8: warns on the psb card when its requirement is off and still renders', function () {
    Setting::set('modules.keuangan.enabled', false);

    $html = bayarHtmlText($this->actingAs(kontenUser('super-admin'))->get($this->url)->assertOk()->getContent());

    expect($html)->toContain('Membutuhkan Keuangan yang belum aktif');
});
