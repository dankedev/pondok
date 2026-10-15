<?php

use App\Filament\Resources\AcademicYears\Pages\ManageAcademicYears;
use App\Filament\Resources\Units\Pages\ManageUnits;
use App\Models\AcademicYear;
use App\Models\AdmissionRequirement;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->f = psbFixture();
    $this->actingAs(psbUser('super-admin'));
});

it('AC-29: hides unit delete when it has requirements', function () {
    AdmissionRequirement::factory()->create(['unit_id' => $this->f->sd->id, 'document_type_id' => $this->f->types['KK']->id]);

    Livewire::test(ManageUnits::class)
        ->assertActionHidden(TestAction::make('delete')->table($this->f->sd))
        ->assertActionVisible(TestAction::make('delete')->table($this->f->slta));
});

it('AC-29: keeps unit delete visible when only an all-units requirement exists', function () {
    AdmissionRequirement::factory()->create(['unit_id' => null, 'document_type_id' => $this->f->types['KK']->id]);

    Livewire::test(ManageUnits::class)->assertActionVisible(TestAction::make('delete')->table($this->f->slta));
});

it('AC-29: hides academic year delete when it has waves', function () {
    psbWave($this->f->y2027);
    $clean = AcademicYear::factory()->create();

    Livewire::test(ManageAcademicYears::class)
        ->assertActionHidden(TestAction::make('delete')->table($this->f->y2027))
        ->assertActionVisible(TestAction::make('delete')->table($clean));
});

it('AC-29: refuses to delete an academic year with waves even when the action is forced', function () {
    psbWave($this->f->y2027);

    try {
        Livewire::test(ManageAcademicYears::class)->callAction(TestAction::make('delete')->table($this->f->y2027));
    } catch (Throwable) {
    }

    expect(AcademicYear::query()->whereKey($this->f->y2027->id)->exists())->toBeTrue();
});
