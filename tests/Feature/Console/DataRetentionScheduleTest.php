<?php

use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/*
| F042 AC-5: jadwal backup (hanya bila `backup.enabled`) dan redaksi snapshot surat.
*/

/**
 * Event jadwal untuk command tertentu.
 */
function retentionScheduled(string $command): ?Event
{
    Artisan::call('schedule:list');

    return collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, $command));
}

it('AC-5: schedules the backup at 01:30 Jakarta time without overlapping', function () {
    $event = retentionScheduled('pondok:backup');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 1 * * *')
        ->and((string) $event->timezone)->toBe('Asia/Jakarta')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(120);
});

it('AC-5: skips the backup until backup.enabled is switched on', function () {
    $event = retentionScheduled('pondok:backup');

    expect($event->filtersPass(app()))->toBeFalse();

    Setting::set('backup.enabled', false);
    expect($event->filtersPass(app()))->toBeFalse();

    Setting::set('backup.enabled', true);
    expect($event->filtersPass(app()))->toBeTrue();
});

it('AC-5: schedules the letter snapshot redaction at 03:00 Jakarta time, always, without overlapping', function () {
    $event = retentionScheduled('pondok:redact-letter-snapshots');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 3 * * *')
        ->and((string) $event->timezone)->toBe('Asia/Jakarta')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(120)
        ->and($event->filtersPass(app()))->toBeTrue();
});

it('F076 AC-8: schedules the activity log pruning at 03:30 Jakarta time only when a retention is set', function () {
    $event = retentionScheduled('pondok:prune-activity-log');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 3 * * *')
        ->and((string) $event->timezone)->toBe('Asia/Jakarta')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(120)
        ->and($event->filtersPass(app()))->toBeFalse();

    Setting::set('retention.activity_log_months', null);
    expect($event->filtersPass(app()))->toBeFalse();

    Setting::set('retention.activity_log_months', 24);
    expect($event->filtersPass(app()))->toBeTrue();
});

it('F076 AC-8: never schedules the unfiltered spatie activitylog:clean', function () {
    Artisan::call('schedule:list');

    $found = collect(app(Schedule::class)->events())
        ->contains(fn (Event $event): bool => str_contains((string) $event->command, 'activitylog:clean'));

    expect($found)->toBeFalse();
});

it('F085 AC-3: schedules exactly one backup event', function () {
    Artisan::call('schedule:list');

    $count = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'pondok:backup'))
        ->count();

    expect($count)->toBe(1);
});

it('F085 AC-3: runs the backup only on days due for the chosen frequency', function () {
    $event = retentionScheduled('pondok:backup');
    $monday = Carbon::parse('2026-10-26 01:30', 'Asia/Jakarta');
    $sunday = Carbon::parse('2026-10-25 01:30', 'Asia/Jakarta');
    $second = Carbon::parse('2026-12-02 01:30', 'Asia/Jakarta');
    $first = Carbon::parse('2026-12-01 01:30', 'Asia/Jakarta');

    Setting::set('backup.enabled', true);

    // Instance lama tanpa frekuensi tetap harian.
    Carbon::setTestNow($monday);
    expect($event->filtersPass(app()))->toBeTrue();

    Setting::set('backup.frequency', 'weekly');
    expect($event->filtersPass(app()))->toBeFalse();
    Carbon::setTestNow($sunday);
    expect($event->filtersPass(app()))->toBeTrue();

    Setting::set('backup.frequency', 'monthly');
    Carbon::setTestNow($second);
    expect($event->filtersPass(app()))->toBeFalse();
    Carbon::setTestNow($first);
    expect($event->filtersPass(app()))->toBeTrue();

    Carbon::setTestNow();
});

it('F085 AC-3: never runs while backup.enabled is off, whatever the frequency', function (?bool $enabled, string $frequency) {
    $event = retentionScheduled('pondok:backup');
    Carbon::setTestNow(Carbon::parse('2026-11-01 01:30', 'Asia/Jakarta')); // Ahad dan tanggal 1

    if ($enabled !== null) {
        Setting::set('backup.enabled', $enabled);
    }
    Setting::set('backup.frequency', $frequency);

    expect($event->filtersPass(app()))->toBeFalse();

    Carbon::setTestNow();
})->with([
    'mati daily' => [false, 'daily'],
    'mati weekly' => [false, 'weekly'],
    'tanpa key monthly' => [null, 'monthly'],
]);

it('F085 AC-4: decides the day in Jakarta time', function () {
    $event = retentionScheduled('pondok:backup');
    Setting::set('backup.enabled', true);

    Setting::set('backup.frequency', 'weekly');
    Carbon::setTestNow(Carbon::parse('2026-10-25 01:30', 'Asia/Jakarta')); // Sabtu 18:30 UTC
    expect($event->filtersPass(app()))->toBeTrue('Ahad WIB');

    Carbon::setTestNow(Carbon::parse('2026-10-24 01:30', 'Asia/Jakarta')); // Jumat 18:30 UTC
    expect($event->filtersPass(app()))->toBeFalse('Sabtu WIB');

    Setting::set('backup.frequency', 'monthly');
    Carbon::setTestNow(Carbon::parse('2026-11-01 01:30', 'Asia/Jakarta')); // 31 Okt 18:30 UTC
    expect($event->filtersPass(app()))->toBeTrue('1 Nov WIB');

    Carbon::setTestNow();
});

it('F085 AC-10: no seeder writes backup.frequency, so an old instance stays daily', function () {
    $written = collect(File::allFiles(database_path('seeders')))
        ->filter(fn (SplFileInfo $file): bool => str_contains((string) file_get_contents($file->getPathname()), 'backup.frequency'))
        ->map->getFilename()->all();

    expect($written)->toBe([]);
});
