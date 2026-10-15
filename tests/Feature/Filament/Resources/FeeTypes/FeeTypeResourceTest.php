<?php

use App\Filament\Resources\FeeTypes\Pages\ManageFeeTypes;
use App\Models\FeeType;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->actingAs(keuanganUser('bendahara'));
});

it('AC-31: creates a fee type from the panel', function () {
    Livewire::test(ManageFeeTypes::class)
        ->callAction('create', [
            'code' => 'spp',
            'name' => 'SPP',
            'frequency' => 'bulanan',
            'applies_to' => 'semua',
            'billing_group' => 'BULANAN',
            'priority' => 100,
            'is_active' => true,
        ])
        ->assertHasNoFormErrors();

    expect(FeeType::query()->sole())->code->toBe('SPP')->name->toBe('SPP');
});

it('AC-31: shows the taken code error', function () {
    FeeType::factory()->create(['code' => 'SPP']);

    Livewire::test(ManageFeeTypes::class)
        ->callAction('create', [
            'code' => 'SPP',
            'name' => 'SPP lagi',
            'frequency' => 'bulanan',
            'applies_to' => 'semua',
            'billing_group' => 'BULANAN',
            'priority' => 100,
            'is_active' => true,
        ])
        ->assertHasFormErrors(['code']);

    expect(FeeType::query()->count())->toBe(1);
});

it('AC-31: edits a fee type from the table', function () {
    $feeType = FeeType::factory()->create(['code' => 'SPP', 'name' => 'SPP']);

    Livewire::test(ManageFeeTypes::class)
        ->callAction(TestAction::make('edit')->table($feeType), [
            'code' => 'SPP',
            'name' => 'SPP Bulanan',
            'frequency' => 'bulanan',
            'applies_to' => 'semua',
            'billing_group' => 'BULANAN',
            'priority' => 50,
            'is_active' => true,
        ])
        ->assertHasNoFormErrors();

    expect($feeType->fresh())->name->toBe('SPP Bulanan')->priority->toBe(50);
});

it('AC-31: has no delete action', function () {
    $feeType = FeeType::factory()->create();

    Livewire::test(ManageFeeTypes::class)
        ->assertCanSeeTableRecords([$feeType])
        ->assertActionDoesNotExist(TestAction::make('delete')->table($feeType))
        ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk());
});
