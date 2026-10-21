<?php

use App\Filament\Pages\FinanceReport;
use App\Jobs\Keuangan\ExportFinanceReportJob;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
    $this->f = laporanFixture();
});

it('VERIFY: a unit-scoped user cannot export another unit by tampering with the public report property', function () {
    Queue::fake();
    $f = $this->f;
    laporanInvoice($f, laporanStudent($f, attributes: ['name' => 'Santri Sltp']), '2026-10-01', 100000, 0, null, '2026-10-10');
    laporanInvoice($f, laporanStudent($f, 'slta', ['name' => 'Santri Slta']), '2026-10-01', 200000, 0, null, '2026-10-10');
    $this->actingAs(keuanganUser('bendahara', ['SLTP']));

    $component = Livewire::test(FinanceReport::class);

    try {
        $component->set('report.filter.unit_id', $f->slta->id)->callAction('export', ['section' => 'tunggakan']);
    } catch (Throwable) {
        // Penolakan berupa pengecualian diterima.
    }

    Queue::assertNothingPushed();

    expect(Activity::query()->where('event', 'exported')->count())->toBe(0);
});

it('VERIFY: a scoped export never contains rows of another unit even with a tampered filter array', function () {
    Queue::fake();
    Storage::fake('private');
    $f = $this->f;
    laporanInvoice($f, laporanStudent($f, attributes: ['name' => 'Santri Sltp']), '2026-10-01', 100000, 0, null, '2026-10-10');
    laporanInvoice($f, laporanStudent($f, 'slta', ['name' => 'Santri Slta']), '2026-10-01', 200000, 0, null, '2026-10-10');
    $user = keuanganUser('bendahara', ['SLTP']);
    $this->actingAs($user);

    Livewire::test(FinanceReport::class)
        ->set('report.filter', ['period_from' => '2026-10', 'period_to' => '2026-10', 'unit_id' => null, 'billing_group' => null])
        ->callAction('export', ['section' => 'tunggakan']);

    Queue::assertPushed(ExportFinanceReportJob::class, 1);

    $job = Queue::pushed(ExportFinanceReportJob::class)->first();
    Bus::dispatchNow($job);

    $content = Storage::disk('private')->get(Storage::disk('private')->allFiles()[0]);

    expect($content)->toContain('Santri Sltp')->not->toContain('Santri Slta')
        ->and(Activity::query()->where('event', 'exported')->sole()->properties['rows'])->toBe(1);
});

it('VERIFY: a tampered export section never reaches the queue', function (string $section) {
    Queue::fake();
    $this->actingAs(keuanganUser('bendahara'));

    try {
        Livewire::test(FinanceReport::class)->callAction('export', ['section' => $section]);
    } catch (Throwable) {
        // Penolakan berupa pengecualian diterima.
    }

    Queue::assertNothingPushed();
})->with(['path traversal' => ['../../etc/passwd'], 'tak dikenal' => ['gaji'], 'kosong' => ['']]);

it('VERIFY: hides the export action while the filter is invalid', function () {
    $this->actingAs(keuanganUser('bendahara'));

    Livewire::test(FinanceReport::class)
        ->assertActionVisible('export')
        ->set('data.period_to', '2026-09')
        ->assertActionHidden('export');
});

it('VERIFY: rejects tampered filter values without an exception', function (string $field, mixed $value) {
    $this->actingAs(keuanganUser('bendahara'));

    $component = Livewire::test(FinanceReport::class)->set("data.{$field}", $value);

    expect($component->errors()->keys())->not->toBe([]);
    expect(tagihanPlainText($component))->not->toContain('pondok.finance_report');
})->with([
    'unit array' => ['unit_id', [1, 2]],
    'unit teks' => ['unit_id', '1; DROP TABLE'],
    'unit tak ada' => ['unit_id', 99999],
    'kelompok array' => ['billing_group', ['A']],
    'kelompok panjang' => ['billing_group', str_repeat('x', 31)],
    'periode array' => ['period_from', ['2026-10']],
    'periode sampah' => ['period_from', '2026-10-01'],
]);

it('VERIFY: accepts a 12 month range and rejects 13 months on the page', function () {
    $f = $this->f;
    laporanInvoice($f, laporanStudent($f), '2025-11-01', 100000);
    laporanInvoice($f, laporanStudent($f), '2026-10-01', 200000);
    $this->actingAs(keuanganUser('bendahara'));

    $component = Livewire::test(FinanceReport::class)->set('data.period_from', '2025-11');
    expect($component->errors()->keys())->toBe([])
        ->and(tagihanPlainText($component))->toContain('Rp 300.000');

    $component->set('data.period_from', '2025-10');
    expect(array_intersect($component->errors()->keys(), ['period_to', 'data.period_to']))->not->toBe([])
        ->and($component->instance()->hasInvalidFilter)->toBeTrue();
});

it('VERIFY: offers only billing groups and units inside the scope', function () {
    $f = $this->f;
    laporanInvoice($f, laporanStudent($f), '2026-10-01', 100000, 0, null, null, 'GRUP-SLTP');
    laporanInvoice($f, laporanStudent($f, 'slta'), '2026-10-01', 100000, 0, null, null, 'GRUP-SLTA');
    $this->actingAs(keuanganUser('bendahara', ['SLTP']));

    Livewire::test(FinanceReport::class)
        ->assertFormFieldExists('billing_group', fn (Select $field): bool => array_keys($field->getOptions()) === ['GRUP-SLTP']);
});

it('VERIFY: scoped page hides subsidies and cash of another unit', function () {
    $f = $this->f;
    $own = laporanStudent($f);
    $other = laporanStudent($f, 'slta');
    $ownInvoice = laporanInvoice($f, $own, '2026-10-01', 400000);
    $otherInvoice = laporanInvoice($f, $other, '2026-10-01', 400000);
    laporanItem($ownInvoice, $f->spp, 500000, 100000, laporanOverride($own, $f->spp, laporanScholarship('Program Sltp', 'Donatur Sltp')));
    laporanItem($otherInvoice, $f->spp, 500000, 100000, laporanOverride($other, $f->spp, laporanScholarship('Program Rahasia', 'Donatur Rahasia')));
    laporanPayment($ownInvoice, 111000, '2026-10-14');
    laporanPayment($otherInvoice, 333000, '2026-10-14');
    $this->actingAs(keuanganUser('bendahara', ['SLTP']));

    $text = tagihanPlainText(Livewire::test(FinanceReport::class));

    expect($text)->toContain('Program Sltp')
        ->and($text)->toContain('Rp 111.000')
        ->and($text)->not->toContain('Program Rahasia')
        ->and($text)->not->toContain('Donatur Rahasia')
        ->and($text)->not->toContain('Rp 333.000');
});

it('VERIFY: renders with a constant number of queries regardless of data volume', function () {
    $f = $this->f;
    $seed = function (int $n) use ($f): void {
        foreach (range(1, $n) as $i) {
            $student = laporanStudent($f);
            $invoice = laporanInvoice($f, $student, '2026-10-01', 400000, 0, null, '2026-10-10');
            laporanItem($invoice, $f->spp, 500000, 100000, laporanOverride($student, $f->spp, laporanScholarship("Program {$student->id}", "Donatur {$student->id}")));
            bayarRecord($invoice, 50000);
        }
    };
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(FinanceReport::class);
        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $total;
    };

    $seed(3);
    $this->actingAs(keuanganUser('bendahara'));
    $count(); // pemanasan: cache izin dan pengaturan
    $small = $count();
    $seed(15);

    expect($count())->toBe($small);
});
