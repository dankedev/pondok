<?php

use App\Filament\Pages\SchoolProfile;
use App\Models\Setting;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = kontenBoot();
    Setting::set('school.name', 'Pondok Lama');
    Setting::set('school.address', 'Alamat lama');
    $this->actingAs(kontenUser('admin-sekolah'));
});

it('AC-38: fills the form from the settings', function () {
    Livewire::test(SchoolProfile::class)
        ->assertFormSet(['name' => 'Pondok Lama', 'address' => 'Alamat lama']);
});

it('AC-38: saves the school profile from the page', function () {
    Livewire::test(SchoolProfile::class)
        ->fillForm(['name' => "Ma'had Al-Khair", 'phone' => '0812-3456-7890', 'email' => 'info@alkhair.sch.id'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(Setting::get('school.name'))->toBe("Ma'had Al-Khair")
        ->and(Setting::get('school.phone'))->toBe('081234567890')
        ->and(Setting::get('school.email'))->toBe('info@alkhair.sch.id')
        ->and(Setting::get('school.address'))->toBe('Alamat lama');
});

it('AC-38: shows an invalid email on the email field and changes nothing', function () {
    $component = Livewire::test(SchoolProfile::class)
        ->fillForm(['name' => 'Nama Baru', 'email' => 'bukan-email'])
        ->call('save')
        ->assertHasFormErrors(['email']);

    expect(Setting::get('school.name'))->toBe('Pondok Lama')
        ->and(kontenSeeMessage($component, 'email_invalid'))->toBeTrue();
});

it('AC-38: requires the name', function () {
    Livewire::test(SchoolProfile::class)
        ->fillForm(['name' => ''])
        ->call('save')
        ->assertHasFormErrors(['name']);

    expect(Setting::get('school.name'))->toBe('Pondok Lama');
});

it('AC-38: uploads a logo and logs the change', function () {
    Livewire::test(SchoolProfile::class)
        ->fillForm(['logo' => kontenImage('logo.png', 300)])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Setting::get('school.logo_path'))->toStartWith('school/')->and(kontenFiles())->toHaveCount(1)
        ->and(kontenActivities())->toHaveCount(1);
});

it('AC-38: refuses a user without the school profile permission', function () {
    $this->actingAs(kontenUser('admin-konten'));

    Livewire::test(SchoolProfile::class)->assertForbidden();

    expect(SchoolProfile::canAccess())->toBeFalse();
});
