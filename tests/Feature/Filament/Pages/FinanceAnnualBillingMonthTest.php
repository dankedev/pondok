<?php

use App\Filament\Pages\Settings\FinanceNumbering;
use App\Models\Setting;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    kontenBoot();
    $this->formats = ['invoice_format' => 'TGH/{YY}/{NNNN}', 'receipt_format' => 'KW/{YY}/{NNNN}'];
});

it('AC-15: a treasurer saves the annual billing month and the change is logged', function () {
    $this->actingAs(kontenUser('bendahara'));
    Activity::query()->delete();

    Livewire::test(FinanceNumbering::class)
        ->fillForm([...$this->formats, 'annual_billing_month' => 7])
        ->call('save')
        ->assertHasNoFormErrors();

    $activity = Activity::query()->where('event', 'numbering_updated')->sole();

    expect((int) Setting::get('keuangan.annual_billing_month'))->toBe(7)
        ->and(json_encode($activity->properties))->toContain('annual_billing_month');
});

it('AC-15: clearing the month leaves annual billing unscheduled', function () {
    Setting::set('keuangan.annual_billing_month', 7);
    $this->actingAs(kontenUser('bendahara'));

    Livewire::test(FinanceNumbering::class)
        ->fillForm([...$this->formats, 'annual_billing_month' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Setting::get('keuangan.annual_billing_month'))->toBeNull();
});

it('AC-15: rejects a month outside 1-12 and keeps the saved setting', function () {
    Setting::set('keuangan.annual_billing_month', 7);
    $this->actingAs(kontenUser('bendahara'));

    Livewire::test(FinanceNumbering::class)
        ->fillForm([...$this->formats, 'annual_billing_month' => 13])
        ->call('save')
        ->assertHasFormErrors(['annual_billing_month']);

    expect((int) Setting::get('keuangan.annual_billing_month'))->toBe(7);
});

it('AC-15: fills the form from the saved setting', function () {
    Setting::set('keuangan.annual_billing_month', 9);
    $this->actingAs(kontenUser('bendahara'));

    Livewire::test(FinanceNumbering::class)->assertFormSet(['annual_billing_month' => 9]);
});
