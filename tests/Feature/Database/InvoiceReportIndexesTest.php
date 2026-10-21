<?php

use App\Models\Invoice;
use Illuminate\Support\Facades\Schema;

/*
| F052 AC-1 dan AC-2. Migrasi: database/migrations/*_add_report_indexes_to_invoices_table.php.
*/

function invoiceIndexColumns(string $table = 'invoices'): array
{
    return collect(Schema::getIndexes($table))->map(fn (array $index): array => $index['columns'])->all();
}

function invoiceReportIndexesMigration(): object
{
    $files = glob(database_path('migrations/*_add_report_indexes_to_invoices_table.php'));
    expect($files)->toHaveCount(1);

    return require $files[0];
}

it('AC-1: adds status-due_on and period-status indexes and keeps the existing ones', function () {
    $columns = invoiceIndexColumns();

    expect($columns)->toContain(['status', 'due_on'])
        ->toContain(['period', 'status'])
        ->toContain(['status', 'period'])
        ->and(collect(Schema::getIndexes('invoices'))->where('unique', true))->not->toBeEmpty()
        ->and(invoiceIndexColumns('students'))->not->toContain(['entered_on']);
});

it('AC-2: rolls back only the new indexes without touching rows', function () {
    $f = tagihanFixture();
    tagihanInvoice(tagihanStudent($f), $f->year);
    tagihanInvoice(tagihanStudent($f), $f->year, ['billing_group' => 'LAIN']);
    $before = Invoice::withoutUnitScope()->orderBy('id')->get()->toArray();
    $uniqueBefore = collect(Schema::getIndexes('invoices'))->where('unique', true)->pluck('columns')->all();

    $migration = invoiceReportIndexesMigration();
    $migration->down();

    expect(invoiceIndexColumns())->not->toContain(['status', 'due_on'])
        ->not->toContain(['period', 'status'])
        ->toContain(['status', 'period'])
        ->and(collect(Schema::getIndexes('invoices'))->where('unique', true)->pluck('columns')->all())->toBe($uniqueBefore);

    $migration->up();

    expect(invoiceIndexColumns())->toContain(['status', 'due_on'])->toContain(['period', 'status'])
        ->and(Invoice::withoutUnitScope()->orderBy('id')->get()->toArray())->toBe($before);
});
