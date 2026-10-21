<?php

use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->travelTo('2026-10-15 09:00:00');
    $this->f = tagihanFixture();
});

it('AC-14: a treasurer generates annual invoices for the chosen academic year from the invoice list', function () {
    $f = $this->f;
    tahunanFeeType($f);
    tagihanStudent($f);
    $user = keuanganUser('bendahara');
    $this->actingAs($user);

    Livewire::test(ListInvoices::class)
        ->assertActionVisible('generateAnnual')
        ->callAction('generateAnnual', ['academic_year_id' => $f->year->id])
        ->assertHasNoFormErrors();

    $expected = __('pondok.invoice.generated', ['period' => '2026/2027', 'created' => 1, 'existing' => 0, 'skipped' => 0]);

    expect(Invoice::withoutUnitScope()->count())->toBe(1)
        ->and(Invoice::withoutUnitScope()->sole()->period->toDateString())->toBe('2026-07-01')
        ->and(Invoice::withoutUnitScope()->sole()->generated_by)->toBe($user->id)
        ->and(invoiceDatabaseNotificationText($user))->toContain('success|')->toContain($expected);
});

it('AC-14: hides the annual generate action without the generate permission', function () {
    $this->actingAs(User::factory()->create()->givePermissionTo(['view-any:invoice', 'view:invoice']));

    Livewire::test(ListInvoices::class)->assertActionHidden('generateAnnual');
});

it('AC-14: shows an error on the academic year field when the billing month is not set', function () {
    $f = $this->f;
    tahunanFeeType($f);
    Setting::set('keuangan.annual_billing_month', null);
    tagihanStudent($f);
    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(ListInvoices::class)
        ->callAction('generateAnnual', ['academic_year_id' => $f->year->id])
        ->assertHasFormErrors(['academic_year_id']);

    expect(Invoice::withoutUnitScope()->count())->toBe(0);
});

it('AC-14: mentions skipped students in the success notification', function () {
    $f = $this->f;
    tahunanFeeType($f);
    tagihanStudent($f);
    tagihanStudent($f, attributes: ['unit_id' => $f->slta->id], cohort: $f->cohortSlta);
    $user = keuanganUser('bendahara');
    $this->actingAs($user);

    Livewire::test(ListInvoices::class)->callAction('generateAnnual', ['academic_year_id' => $f->year->id]);

    expect(invoiceDatabaseNotificationText($user))
        ->toContain('success|')
        ->toContain(__('pondok.invoice.generated', ['period' => '2026/2027', 'created' => 1, 'existing' => 0, 'skipped' => 1]))
        ->toContain(__('pondok.invoice.generated_skipped_hint'));
});
