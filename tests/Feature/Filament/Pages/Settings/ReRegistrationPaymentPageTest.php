<?php

use App\Filament\Pages\Settings\ReRegistrationPayment;
use App\Models\Setting;
use App\Models\Unit;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = kontenBoot();
    $this->slta = Unit::query()->where('code', 'SLTA')->firstOrFail();
    $this->url = '/admin/settings/re-registration-payment';
});

it('AC-10: opens for super-admin and admin-psb with a navigation item', function (string $role) {
    $this->actingAs(kontenUser($role))->get($this->url)->assertOk();

    expect(ReRegistrationPayment::getNavigationItems())->not->toBeEmpty();
})->with(['super-admin', 'admin-psb']);

it('AC-10: forbids the other roles and hides the navigation item', function (string $role) {
    $this->actingAs(kontenUser($role))->get($this->url)->assertForbidden();

    expect(ReRegistrationPayment::getNavigationItems())->toBeEmpty();
})->with(['bendahara', 'admin-sekolah', 'admin-konten', 'kesantrian']);

it('AC-10: is forbidden when the PSB module is off, even for super-admin', function () {
    Setting::set('modules.psb.enabled', false);

    $this->actingAs(kontenUser('super-admin'))->get($this->url)->assertForbidden();
});

it('AC-11: fills the stored values, saves new ones and rejects a negative value', function () {
    Unit::query()->where('code', 'SLTP')->update(['min_reregistration_amount' => 2500000]);
    $this->actingAs(kontenUser('admin-psb'));

    Livewire::test(ReRegistrationPayment::class)
        ->assertFormSet([
            "amounts.{$this->f->sltp->id}" => 2500000,
            "amounts.{$this->slta->id}" => null,
        ])
        ->fillForm(["amounts.{$this->f->sltp->id}" => 3000000, "amounts.{$this->slta->id}" => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->f->sltp->fresh()->min_reregistration_amount)->toBe(3000000)
        ->and($this->slta->fresh()->min_reregistration_amount)->toBeNull();

    Livewire::test(ReRegistrationPayment::class)
        ->fillForm(["amounts.{$this->f->sltp->id}" => -1])
        ->call('save')
        ->assertHasFormErrors(["amounts.{$this->f->sltp->id}"]);

    expect($this->f->sltp->fresh()->min_reregistration_amount)->toBe(3000000);
});

it('AC-9: shows only the units in the scope of an admin-psb scoped to SLTP', function () {
    $this->actingAs(kontenUser('admin-psb', ['SLTP']));

    Livewire::test(ReRegistrationPayment::class)
        ->assertFormFieldExists("amounts.{$this->f->sltp->id}")
        ->assertFormFieldDoesNotExist("amounts.{$this->slta->id}")
        ->assertFormFieldDoesNotExist("amounts.{$this->f->sd->id}");
});
