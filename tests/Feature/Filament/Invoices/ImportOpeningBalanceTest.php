<?php

use App\Filament\Imports\CsvImporter;
use App\Filament\Imports\InvoiceBalanceImporter;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Models\Invoice;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Actions\Imports\Models\FailedImportRow;
use Filament\Actions\Imports\Models\Import;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use League\Csv\Bom;
use League\Csv\Writer;
use Livewire\Livewire;

const IMPORT_SALDO_HEADERS = ['nis', 'fee_type', 'amount'];

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->s = saldoFixture();
});

/**
 * @param  list<array<string, string>>  $rows
 */
function importSaldoCsv(array $rows): UploadedFile
{
    $csv = Writer::fromString();
    $csv->insertOne(IMPORT_SALDO_HEADERS);
    $csv->insertAll(array_map(fn (array $row): array => array_values(array_replace(array_fill_keys(IMPORT_SALDO_HEADERS, ''), $row)), $rows));

    return UploadedFile::fake()->createWithContent('saldo-awal.csv', $csv->toString());
}

/**
 * @param  list<array<string, string>>  $rows
 */
function importSaldoRun(User $user, array $rows)
{
    test()->actingAs($user);

    return Livewire::test(ListInvoices::class)
        ->callAction('import', data: [
            'file' => importSaldoCsv($rows),
            'columnMap' => array_combine(IMPORT_SALDO_HEADERS, IMPORT_SALDO_HEADERS),
        ]);
}

describe('AC-1: akses aksi Import', function () {
    it('shows import and template actions to bendahara and super-admin only', function () {
        foreach (['bendahara', 'super-admin'] as $role) {
            $user = keuanganUser($role);
            $this->actingAs($user);

            expect($user->can('import', Invoice::class))->toBeTrue();
            Livewire::test(ListInvoices::class)
                ->assertActionVisible('import')
                ->assertActionVisible('downloadImportTemplate');
        }
    });

    it('hides the actions and refuses the import for other roles', function (string $role) {
        $user = keuanganUser($role);
        $this->actingAs($user);

        expect($user->can('import', Invoice::class))->toBeFalse();

        if ($user->can('viewAny', Invoice::class)) {
            Livewire::test(ListInvoices::class)
                ->assertActionHidden('import')
                ->assertActionHidden('downloadImportTemplate');

            try {
                Livewire::test(ListInvoices::class)->callAction('import', data: [
                    'file' => importSaldoCsv([saldoRow()]),
                    'columnMap' => array_combine(IMPORT_SALDO_HEADERS, IMPORT_SALDO_HEADERS),
                ]);
            } catch (Throwable) {
                // aksi tersembunyi boleh melempar; yang dinilai adalah efeknya
            }
        }

        expect(Invoice::withoutUnitScope()->count())->toBe(0)->and(Import::query()->count())->toBe(0);
    })->with(['admin-sekolah', 'kesantrian', 'admin-psb']);
});

describe('AC-2: template contoh', function () {
    it('lists three columns with Indonesian headers and a fictitious example, built on the shared importer', function () {
        $columns = collect(InvoiceBalanceImporter::getColumns());

        expect(is_subclass_of(InvoiceBalanceImporter::class, CsvImporter::class))->toBeTrue()
            ->and($columns->map->getName()->all())->toBe(IMPORT_SALDO_HEADERS)
            ->and($columns->map->getExampleHeader()->all())->toBe($columns->map->getLabel()->all())
            ->and($columns->map(fn ($column): array => $column->getExamples())->all())->toBe([['2026001'], ['SPP'], ['1500000']]);

        foreach ($columns->map->getLabel() as $label) {
            expect($label)->not->toBeEmpty()->not->toContain('pondok.');
        }
    });

    it('downloads a BOM UTF-8 CSV template for importers', function () {
        $this->actingAs(keuanganUser('bendahara'));

        $columns = collect(InvoiceBalanceImporter::getColumns());
        $expected = Writer::fromString();
        $expected->insertOne($columns->map->getExampleHeader()->all());
        $expected->insertOne($columns->map(fn ($column): string => (string) collect($column->getExamples())->first())->all());
        $expected->setOutputBOM(Bom::Utf8);

        Livewire::test(ListInvoices::class)
            ->callAction('downloadImportTemplate')
            ->assertFileDownloaded('template-saldo-awal.csv', $expected->toString());
    });

    it('imports the example row successfully when matching data exists', function () {
        $example = collect(InvoiceBalanceImporter::getColumns())
            ->mapWithKeys(fn ($column): array => [$column->getName() => (string) collect($column->getExamples())->first()])
            ->all();

        $this->actingAs(keuanganUser('bendahara'));
        InvoiceBalanceImporter::test()->import($example)->assertImported();

        expect(saldoInvoices($this->s->student)->sole()->total)->toBe(1500000);
    });
});

describe('AC-3 sampai AC-8: isi file lewat panel', function () {
    it('imports valid rows, merges one student into one invoice, and reports failures per row with the counts', function () {
        config(['queue.default' => 'sync']);
        $user = keuanganUser('bendahara');
        $other = tagihanStudent($this->s->f, attributes: ['nis' => '2026002']);

        importSaldoRun($user, [
            saldoRow(),
            saldoRow(['fee_type' => 'ASRAMA', 'amount' => '250000']),
            saldoRow(['nis' => '2026002', 'amount' => '800000']),
            saldoRow(['amount' => '1.500.000']),
            saldoRow(['nis' => '9999999']),
            saldoRow(['fee_type' => 'TIDAKADA']),
            saldoRow(['amount' => '99999']),
        ])->assertHasNoActionErrors();

        $import = Import::query()->latest('id')->firstOrFail();
        $reasons = FailedImportRow::query()->orderBy('id')->pluck('validation_error')->all();

        expect($import->total_rows)->toBe(7)
            ->and($import->successful_rows)->toBe(3)
            ->and($import->getFailedRowsCount())->toBe(4)
            ->and(saldoInvoices($this->s->student))->toHaveCount(1)
            ->and(saldoInvoices($this->s->student)->first()->total)->toBe(1750000)
            ->and(saldoInvoices($other)->sole()->total)->toBe(800000)
            ->and($reasons)->toHaveCount(4);

        foreach ($reasons as $reason) {
            expect($reason)->not->toBeEmpty()->not->toContain('pondok.')->not->toContain('validation.');
        }

        expect($user->notifications()->latest()->firstOrFail()->data['body'])->toContain('3')->toContain('4');
    });

    it('fails every row of a second import of the same file and adds nothing', function () {
        config(['queue.default' => 'sync']);
        $user = keuanganUser('bendahara');

        importSaldoRun($user, [saldoRow()]);
        importSaldoRun($user, [saldoRow()]);

        $second = Import::query()->latest('id')->firstOrFail();

        expect(saldoInvoices())->toHaveCount(1)
            ->and($second->successful_rows)->toBe(0)
            ->and($second->getFailedRowsCount())->toBe(1);
    });

    it('fails NIS of another unit for a scoped importer and imports the ones in scope', function () {
        config(['queue.default' => 'sync']);
        $slta = tagihanStudent($this->s->f, attributes: ['nis' => '2026003'], cohort: $this->s->f->cohortSlta);

        importSaldoRun(keuanganUser('bendahara', ['SLTP']), [saldoRow(), saldoRow(['nis' => '2026003'])]);

        expect(saldoInvoices($this->s->student))->toHaveCount(1)
            ->and(saldoInvoices($slta))->toHaveCount(0)
            ->and(FailedImportRow::query()->count())->toBe(1);
    });
});

describe('AC-12: antrean database', function () {
    it('keeps unit scope and gives unique invoice numbers when the queue worker runs the import', function () {
        config(['queue.default' => 'database']);
        $slta = tagihanStudent($this->s->f, attributes: ['nis' => '2026003'], cohort: $this->s->f->cohortSlta);
        $two = tagihanStudent($this->s->f, attributes: ['nis' => '2026002']);
        $user = keuanganUser('bendahara', ['SLTP']);

        importSaldoRun($user, [saldoRow(), saldoRow(['nis' => '2026002']), saldoRow(['nis' => '2026003'])]);

        expect(saldoInvoices())->toHaveCount(0)->and(DB::table('jobs')->count())->toBeGreaterThan(0);

        Artisan::call('queue:work', ['--stop-when-empty' => true, '--memory' => 2048]);

        $invoices = saldoInvoices();

        expect($invoices)->toHaveCount(2)
            ->and($invoices->pluck('invoice_no')->unique())->toHaveCount(2)
            ->and($invoices->pluck('generated_by')->unique()->all())->toBe([$user->id])
            ->and(saldoInvoices($slta))->toHaveCount(0)
            ->and(FailedImportRow::query()->count())->toBe(1)
            ->and($user->notifications()->count())->toBe(1);
    });
});
