<?php

use App\Filament\Pages\Settings\Identity;
use App\Models\Setting;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = kontenBoot();
    Setting::set('school.name', 'Pondok Lama');
    Setting::set('school.address', 'Alamat lama');
    Setting::set('school.email', 'lama@pondokcontoh.sch.id');
    $this->actingAs(kontenUser('admin-sekolah'));
});

it('AC-38: fills the form from the settings', function () {
    Livewire::test(Identity::class)
        ->assertFormSet(['name' => 'Pondok Lama'])
        ->assertFormFieldDoesNotExist('address');
});

it('AC-38/AC-5: saves the identity from the page and leaves address, email and phone untouched', function () {
    Livewire::test(Identity::class)
        ->fillForm(['name' => "Ma'had Al-Kitab", 'tagline' => 'Motto uji'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(Setting::get('school.name'))->toBe("Ma'had Al-Kitab")
        ->and(Setting::get('school.tagline'))->toBe('Motto uji')
        ->and(Setting::get('school.address'))->toBe('Alamat lama')
        ->and(Setting::get('school.email'))->toBe('lama@pondokcontoh.sch.id')
        ->and(Setting::get('school.phone'))->toBeNull();
});

it('AC-38: requires the name', function () {
    Livewire::test(Identity::class)
        ->fillForm(['name' => ''])
        ->call('save')
        ->assertHasFormErrors(['name']);

    expect(Setting::get('school.name'))->toBe('Pondok Lama');
});

it('AC-38: uploads a logo and logs the change', function () {
    Livewire::test(Identity::class)
        ->fillForm(['logo' => kontenImage('logo.png', 300)])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Setting::get('school.logo_path'))->toStartWith('school/')->and(kontenFiles())->toHaveCount(1)
        ->and(kontenActivities())->toHaveCount(1);
});

it('AC-38: refuses a user without the school profile permission', function () {
    $this->actingAs(kontenUser('bendahara'));

    Livewire::test(Identity::class)->assertForbidden();

    expect(Identity::canAccess())->toBeFalse();
});
