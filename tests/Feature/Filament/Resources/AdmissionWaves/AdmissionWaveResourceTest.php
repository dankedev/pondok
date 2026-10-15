<?php

use App\Filament\Resources\AdmissionWaves\Pages\ManageAdmissionWaves;
use App\Models\AdmissionWave;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->f = psbFixture();
    $this->actingAs(psbUser('admin-psb'));
});

function psbWaveFormData(object $f, array $overrides = []): array
{
    return [
        'academic_year_id' => $f->y2027->id,
        'name' => 'Gelombang 1',
        'opens_on' => '2027-01-01',
        'closes_on' => '2027-03-31',
        'quota' => 150,
        'is_open' => true,
        ...$overrides,
    ];
}

it('AC-26: creates a wave from the modal', function () {
    Livewire::test(ManageAdmissionWaves::class)
        ->assertActionVisible('create')
        ->callAction('create', psbWaveFormData($this->f))
        ->assertHasNoFormErrors();

    $wave = AdmissionWave::query()->sole();

    expect($wave)->name->toBe('Gelombang 1')->quota->toBe(150)->is_open->toBeTrue()->academic_year_id->toBe($this->f->y2027->id)
        ->and($wave->opens_on->format('Y-m-d'))->toBe('2027-01-01');
});

it('AC-26: shows the overlap error on the opens_on field', function () {
    psbWave($this->f->y2027, 'Gelombang 1', '2027-01-01', '2027-03-31');

    Livewire::test(ManageAdmissionWaves::class)
        ->assertActionVisible('create')
        ->callAction('create', psbWaveFormData($this->f, ['name' => 'Gelombang 2', 'opens_on' => '2027-03-01', 'closes_on' => '2027-04-30']))
        ->assertHasFormErrors(['opens_on']);

    expect(AdmissionWave::query()->count())->toBe(1);
});

it('AC-26: closes a wave by editing is_open', function () {
    $wave = psbWave($this->f->y2027, 'Gelombang 1', '2027-01-01', '2027-03-31', ['is_open' => true, 'quota' => 150]);

    Livewire::test(ManageAdmissionWaves::class)
        ->assertActionVisible(TestAction::make('edit')->table($wave))
        ->callAction(TestAction::make('edit')->table($wave), psbWaveFormData($this->f, ['is_open' => false]))
        ->assertHasNoFormErrors();

    expect($wave->fresh()->is_open)->toBeFalse();
});

it('AC-26: has no delete or bulk delete action', function () {
    $wave = psbWave($this->f->y2027);

    Livewire::test(ManageAdmissionWaves::class)
        ->assertActionDoesNotExist(TestAction::make('delete')->table($wave));

    $table = Livewire::test(ManageAdmissionWaves::class)->instance()->getTable();

    expect($table->getFlatBulkActions())->not->toHaveKey('delete')->and($table->getBulkAction('delete'))->toBeNull();
});

it('AC-26: shows the empty state', function () {
    expect(__('pondok.admission_wave.empty'))->not->toBe('pondok.admission_wave.empty');

    Livewire::test(ManageAdmissionWaves::class)->assertSeeText(__('pondok.admission_wave.empty'));
});

it('AC-26: shows an unlimited quota and the dates in short form', function () {
    $wave = psbWave($this->f->y2027, 'Gelombang 1', '2027-01-01', '2027-03-31', ['quota' => null]);

    Livewire::test(ManageAdmissionWaves::class)
        ->assertCanSeeTableRecords([$wave])
        ->assertSeeText('Tanpa batas')
        ->assertSeeText('1 Jan 2027')
        ->assertSeeText('31 Mar 2027')
        ->assertSeeText('2027/2028');
});

it('AC-26: shows a numeric quota', function () {
    psbWave($this->f->y2027, 'Gelombang 1', '2027-01-01', '2027-03-31', ['quota' => 150]);

    Livewire::test(ManageAdmissionWaves::class)->assertSeeText('150')->assertDontSeeText('Tanpa batas');
});

it('AC-26: defaults the academic year of a new wave to the year after the active one', function () {
    Livewire::test(ManageAdmissionWaves::class)
        ->mountAction('create')
        ->assertActionDataSet(['academic_year_id' => $this->f->y2027->id, 'is_open' => true]);
});

it('AC-26: sorts waves by academic year newest first, then by opening date', function () {
    $old = psbWave($this->f->y2026, 'Gelombang 1', '2026-08-01', '2026-09-30');
    $late = psbWave($this->f->y2027, 'Gelombang 2', '2027-04-01', '2027-05-31');
    $early = psbWave($this->f->y2027, 'Gelombang 1', '2027-01-01', '2027-03-31');

    Livewire::test(ManageAdmissionWaves::class)->assertCanSeeTableRecords([$early, $late, $old], inOrder: true);
});

it('AC-26: lets a scoped admin-psb manage waves because waves span all units', function () {
    $this->actingAs(psbUser('admin-psb', ['SLTP']));
    $wave = psbWave($this->f->y2027);

    Livewire::test(ManageAdmissionWaves::class)->assertCanSeeTableRecords([$wave]);
});
