<?php

use App\Filament\Pages\Settings\FinanceNumbering;
use App\Filament\Pages\Settings\ReRegistrationPayment;
use App\Filament\Pages\Settings\StudentNumbering;
use App\Models\Unit;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = kontenBoot();
    $this->slta = Unit::query()->where('code', 'SLTA')->firstOrFail();
});

it('VERIFY: a user scoped to SLTP cannot change SLTA by forging the Livewire state', function () {
    Unit::query()->where('code', 'SLTA')->update(['min_reregistration_amount' => 7000000]);
    $this->actingAs(kontenUser('admin-psb', ['SLTP']));

    try {
        Livewire::test(ReRegistrationPayment::class)
            ->set("data.amounts.{$this->f->sltp->id}", 1000000)
            ->set("data.amounts.{$this->slta->id}", 0)
            ->call('save');
    } catch (Throwable) {
        // Penolakan lewat exception juga dapat diterima; yang dinilai adalah data.
    }

    expect($this->slta->fresh()->min_reregistration_amount)->toBe(7000000);
});

it('VERIFY: rejects a decimal, text or over-one-trillion value in the form and writes nothing', function (mixed $value) {
    Unit::query()->where('code', 'SLTP')->update(['min_reregistration_amount' => 1000000]);
    $this->actingAs(kontenUser('admin-psb'));

    Livewire::test(ReRegistrationPayment::class)
        ->fillForm(["amounts.{$this->f->sltp->id}" => $value])
        ->call('save')
        ->assertHasFormErrors(["amounts.{$this->f->sltp->id}"]);

    expect($this->f->sltp->fresh()->min_reregistration_amount)->toBe(1000000);
})->with(['decimal' => ['1.5'], 'text' => ['abc'], 'above one trillion' => [1000000000001]]);

it('VERIFY: saves zero as zero (no conditions) and an empty field as null (must be paid in full)', function () {
    Unit::query()->where('code', 'SLTA')->update(['min_reregistration_amount' => 4000000]);
    $this->actingAs(kontenUser('admin-psb'));

    Livewire::test(ReRegistrationPayment::class)
        ->fillForm(["amounts.{$this->f->sltp->id}" => 0, "amounts.{$this->slta->id}" => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->f->sltp->fresh()->min_reregistration_amount)->toBe(0)
        ->and($this->slta->fresh()->min_reregistration_amount)->toBeNull();
});

it('VERIFY: the numbering pages keep their navigation items per permission after the base class change', function () {
    $this->actingAs(kontenUser('super-admin'));
    expect(FinanceNumbering::getNavigationItems())->not->toBeEmpty()
        ->and(StudentNumbering::getNavigationItems())->not->toBeEmpty();

    $this->actingAs(kontenUser('bendahara'));
    expect(FinanceNumbering::getNavigationItems())->not->toBeEmpty()
        ->and(StudentNumbering::getNavigationItems())->toBeEmpty()
        ->and(ReRegistrationPayment::getNavigationItems())->toBeEmpty();

    $this->actingAs(kontenUser('admin-psb'));
    expect(FinanceNumbering::getNavigationItems())->toBeEmpty();
});
