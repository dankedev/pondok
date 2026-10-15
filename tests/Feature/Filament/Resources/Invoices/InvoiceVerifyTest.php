<?php

use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\FeeOverride;
use App\Models\FeeType;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Setting;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->travelTo('2026-10-15 09:00:00');
    $this->f = tagihanFixture();
});

function verifySelectCount(Closure $run): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $run();
    DB::disableQueryLog();

    return collect(DB::getQueryLog())->filter(fn (array $q): bool => str_starts_with(strtolower(ltrim($q['query'])), 'select'))->count();
}

it('VERIFY: does not run the generate action after the module is switched off', function () {
    tagihanStudent($this->f);
    $this->actingAs(keuanganUser('bendahara'));
    $component = Livewire::test(ListInvoices::class);

    Setting::set('modules.keuangan.enabled', false);

    try {
        $component->callAction('generate', ['period' => '2026-10-01']);
    } catch (Throwable) {
        // ditolak adalah hasil yang benar
    }

    expect(Invoice::withoutUnitScope()->count())->toBe(0);
});

it('VERIFY: rejects periods outside the select options', function (string $period) {
    tagihanStudent($this->f);
    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(ListInvoices::class)
        ->callAction('generate', ['period' => $period])
        ->assertHasFormErrors(['period']);

    expect(Invoice::withoutUnitScope()->count())->toBe(0);
})->with(['mid month' => '2026-10-15', 'before the year' => '2025-01-01', 'after the year' => '2027-09-01', 'garbage' => 'bukan-tanggal']);

it('VERIFY: shows invoices of archived students to scoped users on the detail page', function () {
    $f = $this->f;
    $student = tagihanStudent($f);
    $invoice = tagihanInvoice($student, $f->year);
    $student->delete();
    $this->actingAs(keuanganUser('bendahara', ['SLTP']));

    $this->get("/admin/invoices/{$invoice->id}")->assertOk()->assertSee($student->name);
    Livewire::test(ListInvoices::class)->assertCanSeeTableRecords([$invoice]);
});

it('VERIFY: keeps select queries flat for the invoice list', function () {
    $f = $this->f;
    $this->actingAs(keuanganUser('bendahara'));
    foreach (range(1, 5) as $i) {
        tagihanInvoice(tagihanStudent($f), $f->year, ['total' => 500000]);
    }
    Livewire::test(ListInvoices::class);

    $small = verifySelectCount(fn () => Livewire::test(ListInvoices::class));

    foreach (range(1, 10) as $i) {
        tagihanInvoice(tagihanStudent($f), $f->year, ['total' => 500000]);
    }

    $large = verifySelectCount(fn () => Livewire::test(ListInvoices::class));

    expect($large)->toBe($small);
});

it('VERIFY: keeps select queries flat for the invoice detail items', function () {
    $f = $this->f;
    $this->actingAs(keuanganUser('bendahara'));
    $make = function (int $count) use ($f): Invoice {
        $student = tagihanStudent($f);
        $invoice = tagihanInvoice($student, $f->year, ['total' => 100000 * $count]);

        foreach (range(1, $count) as $i) {
            $type = FeeType::factory()->monthly()->create();
            $override = FeeOverride::factory()->free()->create(['student_id' => $student->id, 'fee_type_id' => $type->id]);
            InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'fee_type_id' => $type->id, 'base_amount' => 100000, 'discount_amount' => 100000, 'amount' => 0, 'fee_override_id' => $override->id]);
        }

        return $invoice;
    };
    $few = $make(2);
    $many = $make(8);
    Livewire::test(ViewInvoice::class, ['record' => $few->id]);

    $small = verifySelectCount(fn () => Livewire::test(ViewInvoice::class, ['record' => $few->id]));
    $large = verifySelectCount(fn () => Livewire::test(ViewInvoice::class, ['record' => $many->id]));

    expect($large)->toBe($small);
});
