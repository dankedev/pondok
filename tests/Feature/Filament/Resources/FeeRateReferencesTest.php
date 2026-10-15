<?php

use App\Filament\Resources\AcademicYears\Pages\ManageAcademicYears;
use App\Filament\Resources\Cohorts\Pages\ManageCohorts;
use App\Filament\Resources\Dormitories\Pages\ListDormitories;
use App\Filament\Resources\Units\Pages\ManageUnits;
use App\Models\AcademicYear;
use App\Models\Cohort;
use App\Models\Dormitory;
use App\Models\Setting;
use App\Models\Unit;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->f = keuanganFixture();
    $this->actingAs(keuanganUser('super-admin'));
});

/**
 * Per jenis rujukan: halaman, baris yang dirujuk tarif, dan baris bersih tanpa rujukan apa pun.
 *
 * @return array<string, array{0: class-string, 1: Closure(object): mixed, 2: Closure(object): mixed}>
 */
dataset('keuangan references', function () {
    return [
        'cohort' => [
            ManageCohorts::class,
            function (object $f): Cohort {
                keuanganRate($f->type, $f->y2025, $f->sltp, 500000, $f->cohort2025);

                return $f->cohort2025;
            },
            fn (object $f): Cohort => Cohort::factory()->create(['unit_id' => $f->sltp->id, 'entry_year' => 2031, 'name' => 'Angkatan 2031']),
        ],
        'dormitory' => [
            ListDormitories::class,
            function (object $f): Dormitory {
                keuanganRate($f->type, $f->y2025, $f->sltp, 450000, null, $f->d1);

                return $f->d1;
            },
            fn (object $f): Dormitory => $f->d2,
        ],
        'academic year' => [
            ManageAcademicYears::class,
            function (object $f): AcademicYear {
                keuanganRate($f->type, $f->y2025, $f->sltp, 400000);

                return $f->y2025;
            },
            fn (object $f): AcademicYear => $f->y2024,
        ],
        'unit' => [
            ManageUnits::class,
            function (object $f): Unit {
                keuanganRate($f->type, $f->y2025, $f->sd, 300000);

                return $f->sd;
            },
            fn (object $f): Unit => Unit::query()->where('code', 'SLTA')->firstOrFail(),
        ],
    ];
});

it('AC-33: hides delete for records referenced by rates', function (string $page, Closure $referenced, Closure $clean) {
    $referencedRecord = $referenced($this->f);
    $cleanRecord = $clean($this->f);

    Livewire::test($page)
        ->assertActionHidden(TestAction::make('delete')->table($referencedRecord))
        ->assertActionVisible(TestAction::make('delete')->table($cleanRecord));
})->with('keuangan references');

it('AC-33: keeps delete hidden when the keuangan module is disabled', function (string $page, Closure $referenced, Closure $clean) {
    $referencedRecord = $referenced($this->f);
    Setting::set('modules.keuangan.enabled', false);

    Livewire::test($page)
        ->assertActionHidden(TestAction::make('delete')->table($referencedRecord));
})->with('keuangan references');
