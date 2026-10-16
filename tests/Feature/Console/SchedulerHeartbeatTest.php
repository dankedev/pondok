<?php

use Illuminate\Console\Scheduling\Schedule;

it('AC-38: registers the heartbeat as the first scheduled event, queue worker and invoice generator stay', function () {
    $events = collect(app(Schedule::class)->events());

    expect($events->first()->description)->toBe('pondok:scheduler-heartbeat')
        ->and($events->first()->expression)->toBe('* * * * *')
        ->and($events->contains(fn ($e) => str_contains((string) $e->command, 'queue:work') && str_contains((string) $e->command, '--stop-when-empty')))->toBeTrue()
        ->and($events->contains(fn ($e) => str_contains((string) $e->command, 'keuangan:generate-invoices')))->toBeTrue();
});

it('queue runs through the database driver in production and the schedule needs no redis', function () {
    expect(config('queue.connections.database.driver'))->toBe('database')
        ->and(file_get_contents(base_path('config/queue.php')))->toContain("env('QUEUE_CONNECTION', 'database')")
        ->and(file_get_contents(base_path('composer.json')))->not->toContain('predis')->not->toContain('laravel/horizon')->not->toContain('laravel/octane');
});
