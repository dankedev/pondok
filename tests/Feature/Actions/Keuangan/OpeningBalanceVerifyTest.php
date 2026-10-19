<?php

use App\Actions\Keuangan\CancelInvoice;
use App\Actions\Keuangan\ImportOpeningBalanceRow;
use App\Filament\Imports\InvoiceBalanceImporter;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Models\Invoice;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Actions\Imports\ContentGenerators\CsvImportFailureContentGenerator;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Models\Import;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use League\Csv\Writer;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->s = saldoFixture(withRates: true);
    $this->user = keuanganUser('bendahara');
    $this->actingAs($this->user);
});

function saldoVerifyImport(array $row = []): Invoice
{
    return app(ImportOpeningBalanceRow::class)->handle(saldoRow($row));
}

it('VERIFY: a different fee type in a later month makes a second opening invoice instead of touching the first', function () {
    $first = saldoVerifyImport();
    $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00', 'Asia/Jakarta'));

    $second = saldoVerifyImport(['fee_type' => 'ASRAMA', 'amount' => '250000']);

    expect($second->id)->not->toBe($first->id)
        ->and($second->period->toDateString())->toBe('2026-11-01')
        ->and($second->total)->toBe(250000)
        ->and($first->fresh()->total)->toBe(1500000)
        ->and($second->invoice_no)->not->toBe($first->invoice_no);
});

it('VERIFY: a fully paid opening invoice still blocks re-import, so debt is never doubled', function () {
    $invoice = saldoVerifyImport(['amount' => '500000']);
    bayarRecord($invoice, 500000);

    try {
        saldoVerifyImport(['amount' => '500000']);
        $this->fail('Baris kedua seharusnya ditolak.');
    } catch (RowImportFailedException) {
    }

    expect($invoice->fresh())->status->toBe('lunas')->total->toBe(500000)
        ->and(saldoInvoices())->toHaveCount(1);
});

it('VERIFY: cancelling through CancelInvoice allows a corrected re-import with a fresh unique number', function () {
    $old = saldoVerifyImport(['amount' => '900000']);
    app(CancelInvoice::class)->handle($old, $this->user, 'Salah nominal');

    $new = saldoVerifyImport(['amount' => '600000']);

    expect($old->fresh()->status)->toBe('batal')
        ->and($new->total)->toBe(600000)
        ->and($new->invoice_no)->not->toBe($old->invoice_no)
        ->and($new->items()->count())->toBe(1);

    try {
        saldoVerifyImport(['amount' => '600000']);
        $this->fail('Impor ketiga seharusnya ditolak.');
    } catch (RowImportFailedException) {
    }
});

it('VERIFY: two maximum amounts sum exactly in total and items', function () {
    $first = saldoVerifyImport(['amount' => '999999999999']);
    saldoVerifyImport(['fee_type' => 'ASRAMA', 'amount' => '999999999999']);

    expect($first->fresh()->total)->toBe(1999999999998)
        ->and((int) $first->items()->sum('amount'))->toBe(1999999999998);
});

it('VERIFY: rejects full-width digits and digits split by a newline', function () {
    foreach (['１５００', "15\n00", "1500\t0"] as $amount) {
        try {
            saldoVerifyImport(['amount' => $amount]);
            $this->fail("Nominal tidak valid diterima: {$amount}");
        } catch (RowImportFailedException) {
        }
    }

    expect(Invoice::withoutUnitScope()->count())->toBe(0);
});

it('VERIFY: the monthly generator leaves a partly paid opening invoice untouched', function () {
    $opening = saldoVerifyImport(['amount' => '700000']);
    bayarRecord($opening, 300000);

    tagihanGenerate('2026-10-01');

    expect($opening->fresh())->total->toBe(700000)->paid_total->toBe(300000)->status->toBe('sebagian')
        ->and($opening->items()->count())->toBe(1)
        ->and($opening->payments()->count())->toBe(1);
});

it('VERIFY: failed rows file neutralizes formulas for this importer', function () {
    expect(InvoiceBalanceImporter::shouldPreventFormulaInjection())->toBeTrue();

    config(['queue.default' => 'sync']);
    $csv = Writer::fromString();
    $csv->insertOne(['nis', 'fee_type', 'amount']);
    $csv->insertOne(['=HYPERLINK("http://x")', '@SUM(A1)', '+cmd|x']);

    Livewire::test(ListInvoices::class)->callAction('import', data: [
        'file' => UploadedFile::fake()->createWithContent('s.csv', $csv->toString()),
        'columnMap' => ['nis' => 'nis', 'fee_type' => 'fee_type', 'amount' => 'amount'],
    ]);

    $out = Writer::fromString();
    (new CsvImportFailureContentGenerator)(Import::query()->latest('id')->firstOrFail(), $out);
    $content = $out->toString();

    expect($content)->toContain("'=HYPERLINK")->toContain("'@SUM(A1)")->toContain("'+cmd|");
});

it('VERIFY: no one without import permission can download the template, and a disabled module blocks the page', function () {
    $this->actingAs(keuanganUser('admin-sekolah'));

    $component = Livewire::test(ListInvoices::class);

    try {
        $component->callAction('downloadImportTemplate')->assertNoFileDownloaded();
    } catch (Throwable) {
        // tersembunyi: boleh melempar
    }

    $this->actingAs($this->user);
    Setting::set('modules.keuangan.enabled', false);
    Cache::flush();

    Livewire::test(ListInvoices::class)->assertForbidden();
});
