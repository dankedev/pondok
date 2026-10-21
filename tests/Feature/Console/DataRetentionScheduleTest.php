<?php

use App\Models\Setting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

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
