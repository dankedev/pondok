<?php

use App\Filament\Resources\Scholarships\Pages\ManageScholarships;
use App\Models\Scholarship;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
});

it('AC-34: creates a scholarship from the panel', function () {
    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(ManageScholarships::class)
        ->callAction('create', keringananScholarshipData(['sponsor' => 'Yayasan Al-Khair']))
        ->assertHasNoFormErrors();

    $scholarship = Scholarship::query()->sole();

    expect($scholarship)->name->toBe('Beasiswa Tahfidz')->mode->toBe('persen')->default_value->toBe(50)->sponsor->toBe('Yayasan Al-Khair');

    Livewire::test(ManageScholarships::class)
        ->assertCanSeeTableRecords([$scholarship])
        ->assertSeeText('Potongan 50%');
});

it('AC-34: shows the taken name error', function () {
    $this->actingAs(keuanganUser('bendahara'));
    Scholarship::factory()->create(['name' => 'Beasiswa Tahfidz']);

    Livewire::test(ManageScholarships::class)
        ->callAction('create', keringananScholarshipData())
        ->assertHasFormErrors(['name']);

    expect(Scholarship::query()->count())->toBe(1);
});

it('AC-34: edits a scholarship', function () {
    $this->actingAs(keuanganUser('bendahara'));
    $scholarship = Scholarship::factory()->create(['is_active' => true]);

    Livewire::test(ManageScholarships::class)
        ->callAction(TestAction::make('edit')->table($scholarship), keringananScholarshipData(['name' => $scholarship->name, 'is_active' => false]))
        ->assertHasNoFormErrors();

    expect($scholarship->fresh()->is_active)->toBeFalse();
});

it('AC-34: has no delete action', function () {
    $this->actingAs(keuanganUser('bendahara'));
    $scholarship = Scholarship::factory()->create();

    Livewire::test(ManageScholarships::class)
        ->assertActionDoesNotExist(TestAction::make('delete')->table($scholarship))
        ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk());
});

it('AC-34: shows the empty state', function () {
    $this->actingAs(keuanganUser('bendahara'));

    expect(__('pondok.scholarship.empty'))->not->toBe('pondok.scholarship.empty');

    Livewire::test(ManageScholarships::class)->assertSeeText(__('pondok.scholarship.empty'));
});
