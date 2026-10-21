<?php

use App\Filament\Pages\Settings\FinanceNumbering;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Models\FeeType;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = tagihanFixture();
});

it('VERIFY: the monthly generator ignores annual fees and leaves an annual invoice of another group alone', function () {
    $f = $this->f;
    tahunanFeeType($f);
    $student = tagihanStudent($f);
    tahunanGenerate($f->year);
    $annual = tahunanInvoiceFor($student);

    $result = tagihanGenerate('2026-08-01');

    expect($result['created_invoices'])->toBe(1)
        ->and($result['skipped'])->toBe([])
        ->and(tagihanItems(tagihanInvoiceFor($student, 'BULANAN', '2026-08-01'))->keys()->all())->toBe([$f->spp->id])
        ->and(InvoiceItem::query()->where('invoice_id', $annual->id)->count())->toBe(1)
        ->and($annual->fresh()->total)->toBe(750000);
});

it('VERIFY: an annual fee item in an opening balance invoice does not count as already billed', function () {
    $f = $this->f;
    $type = tahunanFeeType($f);
    $student = tagihanStudent($f);
    $opening = tagihanInvoice($student, $f->year, ['billing_group' => 'SALDO_AWAL', 'period' => '2026-07-01']);
    laporanItem($opening, $type, 300000);

    $result = tahunanGenerate($f->year);

    expect($result['created_invoices'])->toBe(1)
        ->and(tahunanInvoiceFor($student))->not->toBeNull();
});

it('VERIFY: an annual item already billed in another period of the year, even when paid, is not billed again', function () {
    $f = $this->f;
    $type = tahunanFeeType($f, attributes: ['billing_group' => 'BULANAN']);
    $student = tagihanStudent($f);
    $paid = tagihanInvoice($student, $f->year, ['period' => '2026-09-01', 'total' => 750000, 'paid_total' => 750000, 'status' => 'lunas']);
    laporanItem($paid, $type, 750000);

    $result = tahunanGenerate($f->year);

    expect($result['created_invoices'])->toBe(0)
        ->and($result['added_items'])->toBe(0)
        ->and($result['existing_invoices'])->toBe(1)
        ->and($result['skipped'])->toBe([])
        ->and(Invoice::withoutUnitScope()->count())->toBe(1)
        ->and(InvoiceItem::query()->count())->toBe(1);
});

it('VERIFY: an inactive annual fee type is not billed', function () {
    $f = $this->f;
    tahunanFeeType($f, attributes: ['is_active' => false]);
    tagihanStudent($f);

    $result = tahunanGenerate($f->year);

    expect($result['created_invoices'])->toBe(0)
        ->and(Invoice::withoutUnitScope()->count())->toBe(0);
});

it('VERIFY: a unit scoped user still bills and de-duplicates students of every unit', function () {
    $this->seed(RolePermissionSeeder::class);
    $f = $this->f;
    tahunanFeeType($f);
    keuanganRate(FeeType::query()->where('code', 'DAFTAR_ULANG')->firstOrFail(), $f->year, $f->slta, 900000);
    $sltp = tagihanStudent($f);
    $slta = tagihanStudent($f, attributes: ['unit_id' => $f->slta->id], cohort: $f->cohortSlta);
    $user = keuanganUser('bendahara', ['SLTP']);
    $this->actingAs($user);

    $first = tahunanGenerate($f->year, $user);
    $second = tahunanGenerate($f->year, $user);

    expect($first['created_invoices'])->toBe(2)
        ->and($second['created_invoices'])->toBe(0)
        ->and($second['existing_invoices'])->toBe(2)
        ->and(Invoice::withoutUnitScope()->where('student_id', $slta->id)->sole()->total)->toBe(900000)
        ->and(Invoice::withoutUnitScope()->where('student_id', $sltp->id)->count())->toBe(1);
});

it('VERIFY: a July-June year with month January bills in January of the following year', function () {
    $f = $this->f;
    tahunanFeeType($f, month: 1);
    $inside = tagihanStudent($f, attributes: ['entered_on' => '2026-12-15']);
    $late = tagihanStudent($f, attributes: ['entered_on' => '2027-02-01']);

    $result = tahunanGenerate($f->year);

    $invoice = tahunanInvoiceFor($inside, period: '2027-01-01');

    expect($result['period'])->toBe('2027-01-01')
        ->and($invoice)->not->toBeNull()
        ->and($invoice->due_on->toDateString())->toBe('2027-01-10')
        ->and($invoice->academic_year_id)->toBe($f->year->id)
        ->and(Invoice::withoutUnitScope()->where('student_id', $late->id)->exists())->toBeFalse();
});

it('VERIFY: only an override active in the billing month applies', function (string $from, ?string $until, int $expectedDiscount) {
    $f = $this->f;
    $type = tahunanFeeType($f, month: 1);
    $student = tagihanStudent($f);
    keringananOverride(['student_id' => $student->id, 'fee_type_id' => $type->id, 'mode' => 'potongan', 'value' => 100000, 'valid_from' => $from, 'valid_until' => $until]);

    tahunanGenerate($f->year);

    expect(tagihanItems(tahunanInvoiceFor($student, period: '2027-01-01'))->sole()->discount_amount)->toBe($expectedDiscount);
})->with([
    'active in January' => ['2027-01-01', '2027-01-31', 100000],
    'ended before January' => ['2026-07-01', '2026-12-31', 0],
    'starts after January' => ['2027-02-01', null, 0],
]);

it('VERIFY: a user without the generate permission cannot run the annual action through Livewire', function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    $f = $this->f;
    tahunanFeeType($f);
    tagihanStudent($f);
    $this->actingAs(User::factory()->create()->givePermissionTo(['view-any:invoice', 'view:invoice']));

    try {
        Livewire::test(ListInvoices::class)->callAction('generateAnnual', ['academic_year_id' => $f->year->id]);
    } catch (Throwable) {
        // Aksi tersembunyi menolak dipanggil.
    }

    expect(Invoice::withoutUnitScope()->count())->toBe(0);
});

it('VERIFY: the invoice list is closed when the keuangan module is off', function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Setting::set('modules.keuangan.enabled', false);

    $this->actingAs(keuanganUser('bendahara'))->get('/admin/invoices')->assertForbidden();
});

it('VERIFY: an invalid billing month from the form is not saved', function (mixed $value) {
    kontenBoot();
    Setting::set('keuangan.annual_billing_month', 7);
    $this->actingAs(kontenUser('bendahara'));

    try {
        Livewire::test(FinanceNumbering::class)
            ->fillForm(['invoice_format' => 'TGH/{YY}/{NNNN}', 'receipt_format' => 'KW/{YY}/{NNNN}', 'annual_billing_month' => $value])
            ->call('save')
            ->assertHasFormErrors(['annual_billing_month']);
    } catch (Throwable) {
        // Select menolak nilai di luar opsi sebelum sampai ke penyimpanan.
    }

    expect((int) Setting::get('keuangan.annual_billing_month'))->toBe(7);
})->with(['zero' => [0], 'thirteen' => [13], 'text' => ['abc'], 'fraction' => ['7.5']]);

it('VERIFY: a stored non-integer billing month is treated as not set', function (mixed $value) {
    $f = $this->f;
    tahunanFeeType($f);
    Setting::set('keuangan.annual_billing_month', $value);
    tagihanStudent($f);

    tagihanAssertRejected(fn () => tahunanGenerate($f->year), 'annual_month_not_set', 'academic_year_id');

    expect(Invoice::withoutUnitScope()->count())->toBe(0);
})->with(['fraction' => ['7.5'], 'padded text' => ['07abc'], 'negative' => [-1]]);
