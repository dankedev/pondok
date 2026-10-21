<?php

use App\Actions\Keuangan\ExportFinanceReportCsv;
use App\Jobs\Keuangan\ExportFinanceReportJob;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
    Storage::fake('private');
    $this->f = laporanFixture();
    $this->user = keuanganUser('bendahara');
    $this->other = keuanganUser('bendahara');
});

function exportJob(User $user, string $section = 'tunggakan', ?int $unitId = null): ExportFinanceReportJob
{
    return new ExportFinanceReportJob($user, $section, laporanFilter(unitId: $unitId)->toArray());
}

function exportFiles(): array
{
    return Storage::disk('private')->allFiles('finance-exports');
}

/**
 * Teks seluruh notifikasi termasuk aksi (URL tombol), untuk pencocokan sederhana.
 */
function exportNotificationData(User $user): string
{
    return $user->notifications()->get()->map(fn ($n): string => json_encode($n->data))->implode("\n");
}

it('AC-3: writes one CSV for the trigger identical to the rows of the action', function () {
    $f = $this->f;
    laporanInvoice($f, laporanStudent($f, attributes: ['name' => 'Santri Satu']), '2026-10-01', 100000, 0, null, '2026-10-10');

    dispatch_sync(exportJob($this->user));

    $files = exportFiles();

    expect($files)->toHaveCount(1)
        ->and($files[0])->toMatch("#^finance-exports/{$this->user->id}/[0-9a-f-]{36}/laporan-keuangan-tunggakan-2026-10-2026-10\\.csv$#")
        ->and(Storage::disk('private')->get($files[0]))->toContain('Santri Satu')->toContain('100000');
});

it('AC-4: applies the unit scope of the trigger in a worker context and restores the guard', function () {
    $f = $this->f;
    laporanInvoice($f, laporanStudent($f, attributes: ['name' => 'Santri SLTP']), '2026-10-01', 100000, 0, null, '2026-10-10');
    laporanInvoice($f, laporanStudent($f, 'slta', ['name' => 'Santri SLTA']), '2026-10-01', 200000, 0, null, '2026-10-10');
    $scoped = keuanganUser('bendahara', ['SLTP']);

    expect(Auth::user())->toBeNull();

    dispatch_sync(exportJob($scoped));

    $content = Storage::disk('private')->get(exportFiles()[0]);

    expect($content)->toContain('Santri SLTP')->not->toContain('Santri SLTA')
        ->and(Auth::user())->toBeNull();
});

it('AC-4: puts a different logged-in user back after the job', function () {
    $this->actingAs($this->other);

    dispatch_sync(exportJob($this->user));

    expect(Auth::user()->is($this->other))->toBeTrue()
        ->and(exportFiles())->toHaveCount(1)
        ->and(Activity::query()->where('event', 'exported')->sole()->causer->is($this->user))->toBeTrue();
});

it('AC-3: exports every section through the job', function () {
    laporanCashFixture($this->f);

    foreach (ExportFinanceReportCsv::SECTIONS as $section) {
        dispatch_sync(exportJob($this->user, $section));
    }

    expect(exportFiles())->toHaveCount(4);
});

it('AC-5: logs the export once with the section, filter, and data row count', function () {
    $f = $this->f;
    laporanInvoice($f, laporanStudent($f), '2026-10-01', 100000, 0, null, '2026-10-10');

    dispatch_sync(exportJob($this->user));

    $activity = Activity::query()->where('log_name', 'keuangan')->where('event', 'exported')->sole();

    expect($activity->causer->is($this->user))->toBeTrue()
        ->and($activity->properties['section'])->toBe('tunggakan')
        ->and($activity->properties['rows'])->toBe(1)
        ->and($activity->properties['filter']['period_from'])->toBe('2026-10');
});

it('AC-6: notifies only the trigger with a relative download link', function () {
    dispatch_sync(exportJob($this->user));

    $uuid = basename(dirname(exportFiles()[0]));
    $path = route('filament.admin.keuangan.finance-export', $uuid, absolute: false);

    expect($this->user->notifications()->count())->toBe(1)
        ->and(invoiceDatabaseNotificationText($this->user))->toContain('success|')
        ->and(exportNotificationData($this->user))->toContain(str_replace('/', '\\/', $path))
        ->and(exportNotificationData($this->user))->not->toContain('http')
        ->and($this->other->notifications()->count())->toBe(0);
});

it('AC-6: runs once with a 300 second timeout below the queue retry_after', function () {
    $job = exportJob($this->user);

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(300)
        ->and((int) config('queue.connections.database.retry_after'))->toBeGreaterThan($job->timeout);
});

it('AC-7: tells the trigger with a danger notice when the filter is no longer valid for them', function () {
    $scoped = keuanganUser('bendahara', ['SLTP']);

    dispatch_sync(exportJob($scoped, unitId: $this->f->slta->id));

    expect(exportFiles())->toBe([])
        ->and(Activity::query()->where('event', 'exported')->count())->toBe(0)
        ->and(invoiceDatabaseNotificationText($scoped))->toContain('danger|')->toContain(__('pondok.finance_report.errors.unit_invalid'))
        ->and(Auth::user())->toBeNull();
});

it('AC-7: a failed job tells the trigger generically and tolerates a deleted user', function () {
    exportJob($this->user)->failed(new RuntimeException('detail internal'));

    expect(invoiceDatabaseNotificationText($this->user))->toContain('danger|')->toContain(__('pondok.finance_report.export.failed'))
        ->not->toContain('detail internal');

    $orphan = new ExportFinanceReportJob(null, 'tunggakan', laporanFilter()->toArray());
    $orphan->failed(new RuntimeException('x'));

    expect(true)->toBeTrue();
});

it('AC-8: does nothing when the trigger lost the permission', function () {
    Role::findByName('bendahara')->revokePermissionTo('view:finance-report');
    $this->user->unsetRelation('roles')->unsetRelation('permissions');

    dispatch_sync(exportJob($this->user));

    expect(exportFiles())->toBe([])
        ->and(Activity::query()->where('event', 'exported')->count())->toBe(0)
        ->and($this->user->notifications()->count())->toBe(0);
});

it('AC-8: does nothing when the keuangan module is disabled before the job runs', function () {
    Setting::set('modules.keuangan.enabled', false);

    dispatch_sync(exportJob($this->user));

    expect(exportFiles())->toBe([])
        ->and(Activity::query()->where('event', 'exported')->count())->toBe(0)
        ->and($this->user->notifications()->count())->toBe(0);
});

it('VERIFY: a scoped job does not leak its scope into the next job of the same worker', function () {
    $f = $this->f;
    laporanInvoice($f, laporanStudent($f, attributes: ['name' => 'Santri SLTP']), '2026-10-01', 100000, 0, null, '2026-10-10');
    laporanInvoice($f, laporanStudent($f, 'slta', ['name' => 'Santri SLTA']), '2026-10-01', 200000, 0, null, '2026-10-10');

    dispatch_sync(exportJob(keuanganUser('bendahara', ['SLTP'])));
    dispatch_sync(exportJob($this->user));

    $contents = collect(exportFiles())->map(fn (string $file): string => Storage::disk('private')->get($file));
    $full = $contents->first(fn (string $csv): bool => str_contains($csv, 'Santri SLTA'));

    expect($contents)->toHaveCount(2)
        ->and($full)->not->toBeNull()->toContain('Santri SLTP')
        ->and($contents->filter(fn (string $csv): bool => str_contains($csv, 'Santri SLTA'))->count())->toBe(1)
        ->and(Auth::user())->toBeNull();
});

it('VERIFY: restores the guard when the export throws an unexpected exception', function () {
    $this->mock(ExportFinanceReportCsv::class)->shouldReceive('handle')->andThrow(new RuntimeException('boom'));
    $this->actingAs($this->other);

    expect(fn () => app()->call([exportJob($this->user), 'handle']))->toThrow(RuntimeException::class)
        ->and(Auth::id())->toBe($this->other->id);
});

it('VERIFY: the queued payload does not carry the password hash of the trigger', function () {
    $payload = serialize(exportJob($this->user));

    expect($payload)->not->toContain($this->user->password)
        ->and($payload)->not->toContain((string) $this->user->remember_token);
});

it('VERIFY: a job serialized before the permission was revoked does nothing when it runs', function () {
    $payload = serialize(exportJob($this->user));
    $this->user->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    app()->call([unserialize($payload), 'handle']);

    expect(exportFiles())->toBe([])
        ->and($this->user->notifications()->count())->toBe(0);
});

it('VERIFY: a job whose trigger was deleted is dropped by the worker without a file or error', function () {
    config(['queue.default' => 'database']);
    laporanInvoice($this->f, laporanStudent($this->f), '2026-10-01', 100000, 0, null, '2026-10-10');

    dispatch(exportJob($this->user));
    $this->user->delete();

    Artisan::call('queue:work', ['--once' => true]);

    expect(exportFiles())->toBe([])
        ->and(DB::table('jobs')->count())->toBe(0);
});
