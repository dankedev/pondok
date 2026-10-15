<?php

use App\Filament\Resources\FeeRates\Pages\ManageFeeRates;
use App\Filament\Resources\FeeTypes\Pages\ManageFeeTypes;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Setting;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Jumlah query selain pencarian saklar modul (`settings`, pola BelongsToModule yang dipakai semua modul).
 */
function keuanganQueryCount(): int
{
    return collect(DB::getQueryLog())->reject(fn (array $q): bool => str_contains($q['query'], '"settings"'))->count();
}

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->f = keuanganFixture();
});

it('VERIFY: rejects a unit outside the user scope submitted through Livewire', function () {
    $this->actingAs(keuanganUser('bendahara', ['SLTP']));

    Livewire::test(ManageFeeRates::class)
        ->callAction('create', keuanganFeeRateData($this->f->type, $this->f->y2025, $this->f->sd));

    expect(FeeRate::query()->where('unit_id', $this->f->sd->id)->count())->toBe(0);
});

it('VERIFY: cannot edit a rate of a unit outside the user scope', function () {
    $sdRate = keuanganRate($this->f->type, $this->f->y2025, $this->f->sd, 300000);
    $this->actingAs(keuanganUser('bendahara', ['SLTP']));

    try {
        Livewire::test(ManageFeeRates::class)
            ->callAction(TestAction::make('edit')->table($sdRate), keuanganFeeRateData($this->f->type, $this->f->y2025, $this->f->sd, ['amount' => 1]));
    } catch (Throwable) {
    }

    expect($sdRate->fresh()->amount)->toBe(300000);
});

it('VERIFY: pages are forbidden over HTTP when the keuangan module is disabled', function (string $url) {
    Setting::set('modules.keuangan.enabled', false);

    $this->actingAs(keuanganUser('super-admin'))->get($url)->assertForbidden();
})->with(['/admin/fee-rates', '/admin/fee-types']);

it('VERIFY: other roles cannot mount the keuangan pages', function () {
    $this->actingAs(keuanganUser('admin-sekolah'));

    Livewire::test(ManageFeeRates::class)->assertForbidden();
    Livewire::test(ManageFeeTypes::class)->assertForbidden();
});

it('VERIFY: the rate table does not run more queries for more rows', function () {
    $this->actingAs(keuanganUser('bendahara'));
    $f = $this->f;
    keuanganRate($f->type, $f->y2025, $f->sltp, 400000, $f->cohort2024, $f->d1);

    Livewire::test(ManageFeeRates::class);
    DB::enableQueryLog();
    Livewire::test(ManageFeeRates::class);
    $few = keuanganQueryCount();

    foreach ([$f->cohort2024, $f->cohort2025, $f->cohort2026] as $cohort) {
        foreach ([null, $f->d1, $f->d2] as $dormitory) {
            keuanganRate($f->type, $f->y2026, $f->sltp, 500000, $cohort, $dormitory);
        }
    }

    DB::flushQueryLog();
    Livewire::test(ManageFeeRates::class);
    $many = keuanganQueryCount();

    expect($many)->toBe($few);
});

it('VERIFY: the fee type table does not run more queries for more rows', function () {
    $this->actingAs(keuanganUser('bendahara'));

    FeeType::factory()->create();
    Livewire::test(ManageFeeTypes::class);
    DB::enableQueryLog();
    Livewire::test(ManageFeeTypes::class);
    $few = keuanganQueryCount();

    FeeType::factory()->count(8)->create();
    DB::flushQueryLog();
    Livewire::test(ManageFeeTypes::class);

    expect(keuanganQueryCount())->toBe($few);
});
