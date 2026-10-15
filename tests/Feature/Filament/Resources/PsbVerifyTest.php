<?php

use App\Filament\Resources\AdmissionRequirements\Pages\ManageAdmissionRequirements;
use App\Filament\Resources\AdmissionTracks\Pages\ManageAdmissionTracks;
use App\Filament\Resources\AdmissionWaves\Pages\ManageAdmissionWaves;
use App\Filament\Resources\FeeRates\Pages\ManageFeeRates;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionWave;
use App\Models\Setting;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->f = psbFixture();
});

it('VERIFY: shows a visible message when a wave rate cannot be saved while psb is disabled', function () {
    $this->actingAs(psbUser('bendahara'));
    $wave = psbWave($this->f->y2027, 'Gelombang 1');
    $type = psbFeeType();
    $rate = keuanganRate($type, $this->f->y2026, $this->f->sltp, 100000, null, null, $wave->id);
    $type->update(['frequency' => 'bulanan']);
    Setting::set('modules.psb.enabled', false);

    $component = Livewire::test(ManageFeeRates::class)
        ->assertActionVisible(TestAction::make('edit')->table($rate))
        ->mountAction(TestAction::make('edit')->table($rate));

    $fieldVisible = true;
    try {
        $component->assertSchemaComponentVisible('admission_wave_id');
    } catch (Throwable) {
        $fieldVisible = false;
    }

    $component->fillForm(['amount' => 120000])->callMountedAction();

    $danger = asramaNotifications()->contains(fn (array $notification): bool => ($notification['status'] ?? null) === 'danger');

    expect($fieldVisible || $danger)->toBeTrue('Kesalahan pada field tersembunyi tidak boleh membuat modal diam.')
        ->and($rate->fresh()->amount)->toBe(100000);
});

it('VERIFY: a scoped admin-psb cannot create a requirement for another unit or all units through the modal', function () {
    $this->actingAs(psbUser('admin-psb', ['SLTP']));

    Livewire::test(ManageAdmissionRequirements::class)
        ->assertActionVisible('create')
        ->callAction('create', psbRequirementData($this->f, ['unit_id' => $this->f->sd->id]));

    Livewire::test(ManageAdmissionRequirements::class)
        ->callAction('create', psbRequirementData($this->f, ['unit_id' => null]));

    expect(AdmissionRequirement::query()->count())->toBe(0);
});

it('VERIFY: a scoped admin-psb may still create a requirement for the own unit', function () {
    $this->actingAs(psbUser('admin-psb', ['SLTP']));

    Livewire::test(ManageAdmissionRequirements::class)
        ->callAction('create', psbRequirementData($this->f, ['unit_id' => $this->f->sltp->id]))
        ->assertHasNoFormErrors();

    expect(AdmissionRequirement::query()->sole()->unit_id)->toBe($this->f->sltp->id);
});

it('VERIFY: psb pages refuse Livewire access when the module is disabled', function (string $page) {
    Setting::set('modules.psb.enabled', false);
    $this->actingAs(psbUser('super-admin'));

    Livewire::test($page)->assertForbidden();
})->with([ManageAdmissionWaves::class, ManageAdmissionTracks::class, ManageAdmissionRequirements::class]);

it('VERIFY: other roles cannot mount the psb pages', function (string $page) {
    $this->actingAs(psbUser('bendahara'));

    Livewire::test($page)->assertForbidden();
})->with([ManageAdmissionWaves::class, ManageAdmissionTracks::class, ManageAdmissionRequirements::class]);

it('VERIFY: the default academic year of a new wave is empty when no later year exists', function () {
    $this->f->y2027->delete();
    $this->actingAs(psbUser('admin-psb'));

    Livewire::test(ManageAdmissionWaves::class)
        ->mountAction('create')
        ->assertActionDataSet(['academic_year_id' => null]);
});

it('VERIFY: the new wave form does not break without an active academic year', function () {
    $this->f->y2026->update(['is_active' => false]);
    $this->actingAs(psbUser('admin-psb'));

    Livewire::test(ManageAdmissionWaves::class)->mountAction('create')->assertHasNoErrors();
});

it('VERIFY: psb tables do not run more queries for more rows', function () {
    $this->actingAs(psbUser('admin-psb'));
    $count = fn (string $page): int => (function () use ($page): int {
        DB::flushQueryLog();
        Livewire::test($page);

        return collect(DB::getQueryLog())->reject(fn (array $q): bool => str_contains($q['query'], '"settings"'))->count();
    })();

    psbWave($this->f->y2027, 'Gelombang 1', '2027-01-01', '2027-01-31');
    AdmissionRequirement::factory()->create(['unit_id' => $this->f->sd->id, 'document_type_id' => $this->f->types['KK']->id]);
    Livewire::test(ManageAdmissionWaves::class);
    Livewire::test(ManageAdmissionRequirements::class);
    DB::enableQueryLog();
    $waves = $count(ManageAdmissionWaves::class);
    $requirements = $count(ManageAdmissionRequirements::class);

    foreach (range(2, 6) as $n) {
        psbWave($this->f->y2027, "Gelombang {$n}", "2027-0{$n}-01", "2027-0{$n}-10");
    }
    foreach (['AKTA', 'IJAZAH', 'RAPOR', 'FOTO', 'BPJS'] as $code) {
        AdmissionRequirement::factory()->create(['unit_id' => $this->f->sltp->id, 'document_type_id' => $this->f->types[$code]->id]);
    }

    expect($count(ManageAdmissionWaves::class))->toBe($waves)
        ->and($count(ManageAdmissionRequirements::class))->toBe($requirements)
        ->and(AdmissionWave::query()->count())->toBe(6);
});
