<?php

use App\Actions\Keuangan\GenerateMonthlyInvoices;
use App\Jobs\Keuangan\GenerateInvoicesJob;
use App\Models\Invoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    $this->travelTo('2026-10-15 09:00:00');
    $this->f = tagihanFixture();
    $this->user = keuanganUser('bendahara');
    $this->other = keuanganUser('bendahara');
});

function monthlyJob(User $user, string $period = '2026-10-01'): GenerateInvoicesJob
{
    return GenerateInvoicesJob::monthly($user, CarbonImmutable::parse($period, 'Asia/Jakarta'));
}

it('AC-7: the monthly job creates the invoices and notifies only the trigger', function () {
    tagihanStudent($this->f, resident: true);

    dispatch_sync(monthlyJob($this->user));

    $invoice = Invoice::withoutUnitScope()->sole();
    $expected = __('pondok.invoice.generated', ['period' => 'Oktober 2026', 'created' => 1, 'existing' => 0, 'skipped' => 0]);

    expect($invoice->generated_by)->toBe($this->user->id)
        ->and($this->user->notifications()->count())->toBe(1)
        ->and(invoiceDatabaseNotificationText($this->user))->toContain('success|')->toContain($expected)->not->toContain(__('pondok.invoice.generated_skipped_hint'))
        ->and($this->other->notifications()->count())->toBe(0);
});

it('AC-7: the notification mentions skipped students', function () {
    tagihanStudent($this->f);
    tagihanStudent($this->f, attributes: ['unit_id' => $this->f->slta->id], cohort: $this->f->cohortSlta);

    dispatch_sync(monthlyJob($this->user));

    expect(invoiceDatabaseNotificationText($this->user))
        ->toContain(__('pondok.invoice.generated', ['period' => 'Oktober 2026', 'created' => 1, 'existing' => 0, 'skipped' => 1]))
        ->toContain(__('pondok.invoice.generated_skipped_hint'));
});

it('AC-8: the annual job creates the invoices and names the academic year', function () {
    tahunanFeeType($this->f);
    tagihanStudent($this->f);

    dispatch_sync(GenerateInvoicesJob::annual($this->user, $this->f->year));

    expect(Invoice::withoutUnitScope()->sole()->generated_by)->toBe($this->user->id)
        ->and(invoiceDatabaseNotificationText($this->user))->toContain('success|')
        ->toContain(__('pondok.invoice.generated', ['period' => '2026/2027', 'created' => 1, 'existing' => 0, 'skipped' => 0]));
});

it('AC-9: a held lock gives a danger notification instead of a failed job', function (string $kind, string $lockPeriod) {
    tahunanFeeType($this->f);
    tagihanStudent($this->f);
    $lock = Cache::lock("keuangan:generate-invoices:{$lockPeriod}", 60);
    expect($lock->get())->toBeTrue();

    $job = $kind === 'monthly' ? monthlyJob($this->user) : GenerateInvoicesJob::annual($this->user, $this->f->year);
    dispatch_sync($job);

    expect(Invoice::withoutUnitScope()->count())->toBe(0)
        ->and(invoiceDatabaseNotificationText($this->user))->toContain('danger|')->toContain(__('pondok.invoice.errors.already_running'))
        ->and(DB::table('failed_jobs')->count())->toBe(0);
})->with([
    'bulanan' => ['monthly', '2026-10'],
    'tahunan' => ['annual', '2026-07'],
]);

it('AC-9: a number conflict gives a danger notification instead of a failed job', function () {
    $this->mock(GenerateMonthlyInvoices::class)
        ->shouldReceive('handle')->andThrow(new RuntimeException(__('pondok.invoice.errors.number_conflict')));

    dispatch_sync(monthlyJob($this->user));

    expect(invoiceDatabaseNotificationText($this->user))->toContain('danger|')->toContain(__('pondok.invoice.errors.number_conflict'))
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('AC-10: a failed job tells the trigger generically without leaking the exception', function () {
    monthlyJob($this->user)->failed(new RuntimeException('detail internal'));

    expect(invoiceDatabaseNotificationText($this->user))->toContain('danger|')->toContain(__('pondok.invoice.generate_failed'))
        ->not->toContain('detail internal');
});

it('AC-10: runs once, outlives the generator lock and the queue does not reclaim it early', function () {
    $job = monthlyJob($this->user);

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job->tries)->toBe(1)
        ->and($job->timeout)->toBeGreaterThanOrEqual(GenerateMonthlyInvoices::LOCK_SECONDS)
        ->and((int) config('queue.connections.database.retry_after'))->toBeGreaterThan($job->timeout);
});
