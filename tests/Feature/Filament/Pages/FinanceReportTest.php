<?php

use App\Filament\Pages\FinanceReport;
use App\Jobs\Keuangan\ExportFinanceReportJob;
use App\Models\Invoice;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
    $this->f = laporanFixture();
});

/**
 * Fixture AC-3 plus satu santri menunggak bernama "Ahmad Menunggak", program "Beasiswa Tahfidz", dan kas 250.000.
 * Oktober: ditagihkan 1.500.000, terbayar 600.000 (40%).
 */
function laporanPageData(object $f): void
{
    $overdue = laporanStudent($f, attributes: ['name' => 'Ahmad Menunggak']);
    $partial = laporanStudent($f);
    $paid = laporanStudent($f);

    laporanInvoice($f, $overdue, '2026-10-01', 500000, 0, null, '2026-10-10');
    $partialInvoice = laporanInvoice($f, $partial, '2026-10-01', 600000, 200000, null, '2026-10-20');
    laporanInvoice($f, $paid, '2026-10-01', 400000, 400000);
    laporanInvoice($f, laporanStudent($f), '2026-10-01', 999000, 0, Invoice::STATUS_CANCELLED);
    laporanInvoice($f, laporanStudent($f), '2026-09-01', 300000);
    laporanInvoice($f, laporanStudent($f), '2026-11-01', 700000);

    laporanItem($partialInvoice, $f->spp, 650000, 50000, laporanOverride($partial, $f->spp, laporanScholarship('Beasiswa Tahfidz', 'Yayasan Al-Khair')));
    laporanPayment($partialInvoice, 250000, '2026-10-14');
}

it('AC-20: renders the report for the selected period', function () {
    laporanPageData($this->f);
    $this->actingAs(keuanganUser('bendahara'));

    $component = Livewire::test(FinanceReport::class)
        ->assertSet('data.period_from', '2026-10')
        ->assertSet('data.period_to', '2026-10');
    $text = tagihanPlainText($component);

    expect($text)->toContain('Rp 1.500.000')
        ->and($text)->toContain('40%')
        ->and($text)->toContain('Ahmad Menunggak')
        ->and($text)->toContain('Beasiswa Tahfidz')
        ->and($text)->toContain('Rp 250.000');

    $component->set('data.period_from', '2026-09')->set('data.period_to', '2026-09');
    $text = tagihanPlainText($component);

    expect($text)->toContain('Rp 300.000')
        ->and($text)->not->toContain('Rp 1.500.000');
});

it('AC-20: shows field errors for an invalid range and keeps the last valid report', function () {
    laporanPageData($this->f);
    $this->actingAs(keuanganUser('bendahara'));

    $component = Livewire::test(FinanceReport::class);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $component->set('data.period_to', '2026-09');
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    $errorKeys = $component->errors()->keys();
    $aggregates = array_filter($queries, fn (string $sql): bool => preg_match('/\b(sum|count)\s*\(/i', $sql) === 1 && preg_match('/"(invoices|payments|invoice_items|payment_allocations)"/', $sql) === 1);

    expect(array_intersect($errorKeys, ['period_to', 'data.period_to']))->not->toBe([])
        ->and(tagihanPlainText($component))->toContain('Rp 1.500.000')
        ->and($aggregates)->toBe([]);
});

it('AC-21: shows empty states without data', function () {
    $this->actingAs(keuanganUser('bendahara'));

    $this->get('/admin/finance-report')->assertOk();

    $component = Livewire::test(FinanceReport::class);
    $html = $component->html();
    $text = tagihanPlainText($component);

    foreach (['empty_invoices', 'empty_arrears', 'empty_subsidies', 'empty_cash'] as $key) {
        expect(__("pondok.finance_report.{$key}"))->not->toBe("pondok.finance_report.{$key}");
        $component->assertSee(__("pondok.finance_report.{$key}"));
    }

    expect($text)->not->toMatch('/Rp\s*-/')
        ->and($html)->not->toMatch('/pondok\.(finance_report|invoice|payment|navigation|common)\./');
});

it('AC-22: limits the report to the user units', function () {
    $f = $this->f;
    laporanInvoice($f, laporanStudent($f, attributes: ['name' => 'Santri Sltp Menunggak']), '2026-10-01', 111000, 0, null, '2026-10-10');
    laporanInvoice($f, laporanStudent($f, 'slta', ['name' => 'Santri Slta Menunggak']), '2026-10-01', 777000, 0, null, '2026-10-10');
    $this->actingAs(keuanganUser('bendahara', ['SLTP']));

    $component = Livewire::test(FinanceReport::class);
    $text = tagihanPlainText($component);

    expect($text)->toContain('Santri Sltp Menunggak')
        ->and($text)->not->toContain('Santri Slta Menunggak')
        ->and($text)->toContain('Rp 111.000')
        ->and($text)->not->toContain('Rp 777.000');

    $component->assertFormFieldExists('unit_id', fn (Select $field): bool => array_map('intval', array_keys($field->getOptions())) === [$f->sltp->id]);

    $component->set('data.unit_id', $f->slta->id);
    $text = tagihanPlainText($component);

    expect(array_intersect($component->errors()->keys(), ['unit_id', 'data.unit_id']))->not->toBe([])
        ->and($text)->not->toContain('Santri Slta Menunggak')
        ->and($text)->not->toContain('Rp 777.000');
});

it('AC-1: queues the CSV export and shows a processing notice', function () {
    Queue::fake();
    Storage::fake('private');
    laporanPageData($this->f);
    $user = keuanganUser('bendahara');
    $this->actingAs($user);

    Livewire::test(FinanceReport::class)
        ->assertActionVisible('export')
        ->callAction('export', ['section' => 'tunggakan'])
        ->assertNoFileDownloaded()
        ->assertNotified(__('pondok.finance_report.export.queued'));

    Queue::assertPushed(ExportFinanceReportJob::class, 1);
    Queue::assertPushed(ExportFinanceReportJob::class, fn (ExportFinanceReportJob $job): bool => $job->user->is($user)
        && $job->section === 'tunggakan'
        && $job->filter === ['period_from' => '2026-10', 'period_to' => '2026-10', 'unit_id' => null, 'billing_group' => null]);

    expect(Storage::disk('private')->allFiles())->toBe([])
        ->and(Activity::query()->where('event', 'exported')->count())->toBe(0);
});

it('AC-2: does not queue the export after the permission is revoked', function () {
    Queue::fake();
    laporanPageData($this->f);
    $user = keuanganUser('bendahara');
    $this->actingAs($user);

    $component = Livewire::test(FinanceReport::class);

    Role::findByName('bendahara')->revokePermissionTo('view:finance-report');
    $user->unsetRelation('roles')->unsetRelation('permissions');

    try {
        $component->callAction('export', ['section' => 'tunggakan']);
    } catch (Throwable) {
        // Aksi ditolak boleh berupa pengecualian; yang diperiksa adalah akibatnya.
    }

    Queue::assertNothingPushed();

    expect(Activity::query()->where('event', 'exported')->count())->toBe(0);
});

it('AC-2: does not queue the export after the keuangan module is disabled', function () {
    Queue::fake();
    laporanPageData($this->f);
    $this->actingAs(keuanganUser('bendahara'));

    $component = Livewire::test(FinanceReport::class);

    Setting::set('modules.keuangan.enabled', false);

    try {
        $component->callAction('export', ['section' => 'tunggakan']);
    } catch (Throwable) {
        // Aksi ditolak boleh berupa pengecualian; yang diperiksa adalah akibatnya.
    }

    Queue::assertNothingPushed();

    expect(Activity::query()->where('event', 'exported')->count())->toBe(0);
});
