<?php

use App\Actions\Core\PruneActivityLog;
use App\Models\Setting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/*
| F076 AC-1 s.d. AC-7: pembersihan activity log lama (allowlist log_name akademik dan konten).
*/

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 23)->setTime(10, 0));
});

/**
 * Sisipkan satu baris activity_log berumur tertentu.
 */
function pruneLog(?string $logName, int $monthsAgo, int $count = 1): void
{
    $rows = [];
    $at = now()->subMonthsNoOverflow($monthsAgo)->toDateTimeString();

    for ($i = 0; $i < $count; $i++) {
        $rows[] = ['log_name' => $logName, 'description' => 'uji', 'created_at' => $at, 'updated_at' => $at];
    }

    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('activity_log')->insert($chunk);
    }
}

function pruneCount(?string $logName): int
{
    return DB::table('activity_log')->where('log_name', $logName)->count();
}

it('AC-1: deletes nothing and logs nothing while the setting is empty', function () {
    pruneLog('akademik', 60);
    pruneLog('konten', 60);

    expect(app(PruneActivityLog::class)->handle())->toBe(0)
        ->and(DB::table('activity_log')->count())->toBe(2);

    Setting::set('retention.activity_log_months', null);

    expect(app(PruneActivityLog::class)->handle())->toBe(0)
        ->and(Activity::query()->where('event', 'prune')->count())->toBe(0);
});

it('AC-2: deletes akademik and konten logs older than the retention and keeps newer ones', function () {
    Setting::set('retention.activity_log_months', 24);
    pruneLog('akademik', 25);
    pruneLog('konten', 25);
    pruneLog('akademik', 23);
    pruneLog('konten', 23);

    expect(app(PruneActivityLog::class)->handle())->toBe(2)
        ->and(pruneCount('akademik'))->toBe(1)
        ->and(pruneCount('konten'))->toBe(1);
});

it('AC-3: never touches audit logs, unknown names or null log names', function () {
    Setting::set('retention.activity_log_months', 24);
    $names = ['keuangan', 'sistem', 'pengaturan', 'dokumen', 'psb', 'default', 'lainnya', null];

    foreach ($names as $name) {
        pruneLog($name, 60);
    }

    expect(app(PruneActivityLog::class)->handle())->toBe(0);

    foreach ($names as $name) {
        expect(pruneCount($name))->toBe(1, 'log_name '.var_export($name, true));
    }
});

it('AC-4: deletes a large backlog in chunks', function () {
    Setting::set('retention.activity_log_months', 24);
    pruneLog('konten', 36, 1200);

    DB::enableQueryLog();
    $deleted = app(PruneActivityLog::class)->handle();
    $deletes = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_starts_with(strtolower($q['query']), 'delete'))->count();
    DB::disableQueryLog();

    expect($deleted)->toBe(1200)
        ->and(pruneCount('konten'))->toBe(0)
        ->and($deletes)->toBeGreaterThan(1);
});

it('AC-5: records exactly one sistem prune activity and nothing on a second run', function () {
    Setting::set('retention.activity_log_months', 24);
    pruneLog('akademik', 30, 2);
    pruneLog('konten', 30);

    expect(app(PruneActivityLog::class)->handle())->toBe(3);

    $prunes = Activity::query()->where('event', 'prune')->get();

    expect($prunes)->toHaveCount(1)
        ->and($prunes->first()->log_name)->toBe('sistem')
        ->and($prunes->first()->properties['deleted'])->toBe(3)
        ->and($prunes->first()->properties['months'])->toBe(24)
        ->and($prunes->first()->properties['log_names'])->toBe(['akademik', 'konten']);

    expect(app(PruneActivityLog::class)->handle())->toBe(0)
        ->and(Activity::query()->where('event', 'prune')->count())->toBe(1);
});

it('AC-6: refuses a retention below 24 months written straight to the database', function () {
    Setting::set('retention.activity_log_months', 12);
    pruneLog('akademik', 13);

    expect(app(PruneActivityLog::class)->handle())->toBe(0)
        ->and(pruneCount('akademik'))->toBe(1);
});

it('AC-7: the command exits 0 and prints the count, also when the setting is empty', function () {
    expect(Artisan::call('pondok:prune-activity-log'))->toBe(0)
        ->and(Artisan::output())->toContain(__('pondok.data_retention.activity_log_pruned', ['count' => 0]));

    Setting::set('retention.activity_log_months', 24);
    pruneLog('konten', 30, 2);

    expect(Artisan::call('pondok:prune-activity-log'))->toBe(0)
        ->and(Artisan::output())->toContain(__('pondok.data_retention.activity_log_pruned', ['count' => 2]));
});

it('VERIFY: a non-numeric or below-minimum stored setting deletes nothing, a numeric string works', function () {
    pruneLog('akademik', 60);

    foreach (['abc', '', '23.9', '-5', '0'] as $value) {
        Setting::set('retention.activity_log_months', $value);

        expect(app(PruneActivityLog::class)->handle())->toBe(0, 'nilai '.var_export($value, true));
    }

    Setting::set('retention.activity_log_months', '36');

    expect(app(PruneActivityLog::class)->handle())->toBe(1);
});

it('VERIFY: a row exactly at the cutoff is kept and one second older is deleted', function () {
    Setting::set('retention.activity_log_months', 24);
    $cutoff = now()->subMonthsNoOverflow(24);

    foreach ([['konten', $cutoff], ['akademik', $cutoff->copy()->subSecond()]] as [$name, $at]) {
        DB::table('activity_log')->insert(['log_name' => $name, 'description' => 'uji', 'created_at' => $at->toDateTimeString(), 'updated_at' => $at->toDateTimeString()]);
    }

    expect(app(PruneActivityLog::class)->handle())->toBe(1)
        ->and(pruneCount('konten'))->toBe(1)
        ->and(pruneCount('akademik'))->toBe(0);
});
