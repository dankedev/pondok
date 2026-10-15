<?php

use App\Filament\Resources\FeeRates\Pages\ManageFeeRates;
use App\Models\FeeRate;
use App\Models\Setting;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->f = keuanganFixture();
});

function keuanganRateFormData(object $f, array $overrides = []): array
{
    return [
        'fee_type_id' => $f->type->id,
        'effective_from_year_id' => $f->y2025->id,
        'unit_id' => $f->sltp->id,
        'cohort_id' => null,
        'dormitory_id' => null,
        'amount' => 450000,
        ...$overrides,
    ];
}

it('AC-30: limits rates and unit options to the user units', function () {
    $f = $this->f;
    $sltpRate = keuanganRate($f->type, $f->y2025, $f->sltp, 400000);
    $sdRate = keuanganRate($f->type, $f->y2025, $f->sd, 300000);
    $this->actingAs(keuanganUser('bendahara', ['SLTP']));

    Livewire::test(ManageFeeRates::class)
        ->assertCanSeeTableRecords([$sltpRate])
        ->assertCanNotSeeTableRecords([$sdRate])
        ->mountAction('create')
        ->assertSchemaComponentExists('unit_id', checkComponentUsing: fn ($component): bool => array_keys($component->getOptions()) === [$f->sltp->id]);
});

it('AC-32: creates a rate from the panel', function () {
    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(ManageFeeRates::class)
        ->callAction('create', keuanganRateFormData($this->f))
        ->assertHasNoFormErrors();

    $rate = FeeRate::query()->sole();

    expect($rate->amount)->toBe(450000);

    Livewire::test(ManageFeeRates::class)
        ->assertCanSeeTableRecords([$rate])
        ->assertSeeText('450.000')
        ->assertSeeText('Rp');
});

it('AC-32: shows the duplicate rate error', function () {
    $this->actingAs(keuanganUser('bendahara'));
    keuanganRate($this->f->type, $this->f->y2025, $this->f->sltp, 400000);

    Livewire::test(ManageFeeRates::class)
        ->callAction('create', keuanganRateFormData($this->f))
        ->assertHasFormErrors(['effective_from_year_id']);

    expect(FeeRate::query()->count())->toBe(1);
});

it('AC-32: edits a rate and logs the change', function () {
    $this->actingAs(keuanganUser('bendahara'));
    $rate = keuanganRate($this->f->type, $this->f->y2025, $this->f->sltp, 400000);

    Livewire::test(ManageFeeRates::class)
        ->callAction(TestAction::make('edit')->table($rate), keuanganRateFormData($this->f, ['amount' => 475000]))
        ->assertHasNoFormErrors();

    $updated = Activity::query()->where('subject_id', $rate->id)->where('event', 'updated')->sole();

    expect($rate->fresh()->amount)->toBe(475000)
        ->and($updated->log_name)->toBe('keuangan')
        ->and($updated->properties['attributes']['amount'])->toBe(475000);
});

it('AC-32: has no delete action', function () {
    $this->actingAs(keuanganUser('bendahara'));
    $rate = keuanganRate($this->f->type, $this->f->y2025, $this->f->sltp, 400000);

    Livewire::test(ManageFeeRates::class)
        ->assertActionDoesNotExist(TestAction::make('delete')->table($rate))
        ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk());
});

it('AC-21 (F009): shows the wave field for psb fees only', function () {
    $this->actingAs(keuanganUser('bendahara'));
    $registration = psbFeeType();

    Livewire::test(ManageFeeRates::class)
        ->mountAction('create')
        ->fillForm(['fee_type_id' => $registration->id])
        ->assertSchemaComponentVisible('admission_wave_id')
        ->fillForm(['fee_type_id' => $this->f->type->id])
        ->assertSchemaComponentHidden('admission_wave_id');
});

it('AC-21 (F009): saves a rate with a wave and lists the wave name', function () {
    $this->actingAs(keuanganUser('bendahara'));
    $registration = psbFeeType();
    $wave = psbWave($this->f->y2026, 'Gelombang 1');
    $plain = keuanganRate($registration, $this->f->y2025, $this->f->sd, 100000);

    Livewire::test(ManageFeeRates::class)
        ->assertActionVisible('create')
        ->callAction('create', keuanganRateFormData($this->f, ['fee_type_id' => $registration->id, 'admission_wave_id' => $wave->id]))
        ->assertHasNoFormErrors();

    $rate = FeeRate::query()->where('admission_wave_id', $wave->id)->sole();

    Livewire::test(ManageFeeRates::class)
        ->assertCanSeeTableRecords([$rate, $plain])
        ->assertSeeText('Gelombang 1')
        ->assertSeeText('Semua gelombang');

    expect($rate->fresh()->amount)->toBe(450000);
});

it('AC-21 (F009): clears the wave when all waves is chosen', function () {
    $this->actingAs(keuanganUser('bendahara'));
    $registration = psbFeeType();
    $wave = psbWave($this->f->y2026, 'Gelombang 1');
    $rate = keuanganRate($registration, $this->f->y2025, $this->f->sltp, 100000, null, null, $wave->id);

    Livewire::test(ManageFeeRates::class)
        ->assertActionVisible(TestAction::make('edit')->table($rate))
        ->callAction(TestAction::make('edit')->table($rate), keuanganRateFormData($this->f, ['fee_type_id' => $registration->id, 'admission_wave_id' => null, 'amount' => 100000]))
        ->assertHasNoFormErrors();

    expect($rate->fresh()->admission_wave_id)->toBeNull();
});

it('AC-21 (F009): prefills the wave when editing a psb rate', function () {
    $this->actingAs(keuanganUser('bendahara'));
    $wave = psbWave($this->f->y2026, 'Gelombang 1');
    $registration = psbFeeType();
    $rate = keuanganRate($registration, $this->f->y2025, $this->f->sltp, 100000, null, null, $wave->id);

    Livewire::test(ManageFeeRates::class)
        ->mountAction(TestAction::make('edit')->table($rate))
        ->assertSchemaComponentVisible('admission_wave_id')
        ->assertSchemaComponentStateSet('admission_wave_id', $wave->id);
});

it('AC-21 (F009): hides the wave field and keeps the value when psb is disabled', function () {
    $this->actingAs(keuanganUser('bendahara'));
    $registration = psbFeeType();
    $wave = psbWave($this->f->y2026, 'Gelombang 1');
    $rate = keuanganRate($registration, $this->f->y2025, $this->f->sltp, 100000, null, null, $wave->id);
    Setting::set('modules.psb.enabled', false);

    Livewire::test(ManageFeeRates::class)
        ->mountAction('create')
        ->fillForm(['fee_type_id' => $registration->id])
        ->assertSchemaComponentHidden('admission_wave_id');

    Livewire::test(ManageFeeRates::class)
        ->assertActionVisible(TestAction::make('edit')->table($rate))
        ->callAction(TestAction::make('edit')->table($rate), keuanganRateFormData($this->f, ['fee_type_id' => $registration->id, 'amount' => 125000]))
        ->assertHasNoFormErrors();

    expect($rate->fresh())->amount->toBe(125000)->admission_wave_id->toBe($wave->id);
});

it('AC-21 (F009): drops the wave of a rate whose fee type is no longer a psb fee when it is edited', function () {
    $this->actingAs(keuanganUser('bendahara'));
    $wave = psbWave($this->f->y2026, 'Gelombang 1');
    $registration = psbFeeType();
    $rate = keuanganRate($registration, $this->f->y2025, $this->f->sltp, 100000, null, null, $wave->id);
    $registration->update(['frequency' => 'bulanan']);

    Livewire::test(ManageFeeRates::class)
        ->assertActionVisible(TestAction::make('edit')->table($rate))
        ->callAction(TestAction::make('edit')->table($rate), keuanganRateFormData($this->f, ['fee_type_id' => $registration->id, 'amount' => 110000]))
        ->assertHasNoFormErrors();

    expect($rate->fresh())->amount->toBe(110000)->admission_wave_id->toBeNull();
});
