<?php

use App\Actions\Keuangan\GenerateMonthlyInvoices;
use App\Jobs\Keuangan\GenerateInvoicesJob;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    $this->travelTo('2026-10-15 09:00:00');
    $this->f = tagihanFixture();
    $this->user = keuanganUser('bendahara');
    config(['queue.default' => 'database']);
});

function runQueueWorker(): void
{
    Artisan::call('queue:work', ['--stop-when-empty' => true, '--queue' => 'default', '--sleep' => 0, '--memory' => 4096]);
}

it('VERIFY: a monthly job survives database queue serialization and notifies the trigger', function () {
    tagihanStudent($this->f, resident: true);

    dispatch(GenerateInvoicesJob::monthly($this->user, CarbonImmutable::parse('2026-10-01', 'Asia/Jakarta')));
    expect(DB::table('jobs')->count())->toBe(1);

    runQueueWorker();

    expect(Invoice::withoutUnitScope()->count())->toBe(1)
        ->and(Invoice::withoutUnitScope()->sole()->generated_by)->toBe($this->user->id)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(invoiceDatabaseNotificationText($this->user))->toContain('success|')->toContain('Oktober 2026');
});

it('VERIFY: an annual job survives database queue serialization and names the academic year', function () {
    tahunanFeeType($this->f);
    tagihanStudent($this->f);

    dispatch(GenerateInvoicesJob::annual($this->user, $this->f->year));
    runQueueWorker();

    expect(Invoice::withoutUnitScope()->count())->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(invoiceDatabaseNotificationText($this->user))->toContain('success|')->toContain('2026/2027');
});

it('VERIFY: a job whose user was deleted before it runs is dropped without creating invoices', function () {
    tagihanStudent($this->f, resident: true);
    dispatch(GenerateInvoicesJob::monthly($this->user, CarbonImmutable::parse('2026-10-01', 'Asia/Jakarta')));
    DB::table('users')->where('id', $this->user->id)->delete();

    runQueueWorker();

    expect(Invoice::withoutUnitScope()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('VERIFY: failed() with a missing user does not throw', function () {
    $job = GenerateInvoicesJob::monthly($this->user, CarbonImmutable::parse('2026-10-01', 'Asia/Jakarta'));
    (fn () => $this->user = null)->call($job);

    $job->failed(new RuntimeException('x'));

    expect(DB::table('notifications')->count())->toBe(0);
});

it('VERIFY: an unexpected exception is rethrown, not swallowed as a danger notification', function () {
    $this->mock(GenerateMonthlyInvoices::class)
        ->shouldReceive('handle')->andThrow(new RuntimeException('SQLSTATE secret'));

    expect(fn () => dispatch_sync(GenerateInvoicesJob::monthly($this->user, CarbonImmutable::parse('2026-10-01', 'Asia/Jakarta'))))
        ->toThrow(RuntimeException::class, 'SQLSTATE secret');
    expect(invoiceDatabaseNotificationText($this->user))->not->toContain('SQLSTATE');
});
