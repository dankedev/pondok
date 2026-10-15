<?php

use App\Filament\Resources\FeeRates\Pages\ManageFeeRates;
use App\Models\FeeRate;
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
