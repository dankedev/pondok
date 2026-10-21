<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

it('AC-13: the command prunes orphan proofs and prints the count', function () {
    Storage::fake('private');
    Storage::disk('private')->put('payment-proofs/yatim.jpg', 'bukti');
    touch(Storage::disk('private')->path('payment-proofs/yatim.jpg'), now()->subDays(2)->getTimestamp());

    $exit = Artisan::call('keuangan:prune-payment-proofs');

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain(__('pondok.payment.proofs_pruned', ['count' => 1]));
    Storage::disk('private')->assertMissing('payment-proofs/yatim.jpg');
});

it('AC-13: schedules the command daily in Jakarta time next to the queue worker', function () {
    $events = collect(app(Schedule::class)->events());
    $prune = $events->first(fn ($event) => str_contains((string) $event->command, 'keuangan:prune-payment-proofs'));

    expect($prune)->not->toBeNull()
        ->and($prune->expression)->toMatch('/^\d+ \d+ \* \* \*$/')
        ->and((string) $prune->timezone)->toBe('Asia/Jakarta')
        ->and($prune->withoutOverlapping)->toBeTrue()
        ->and($events->contains(fn ($event) => str_contains((string) $event->command, 'queue:work') && str_contains((string) $event->command, '--stop-when-empty')))->toBeTrue();
});

it('AC-13: the new lang keys have text', function () {
    expectTranslatedKeys([
        'pondok.invoice.generate_queued',
        'pondok.invoice.generate_queued_body',
        'pondok.invoice.generate_failed',
        'pondok.payment.proofs_pruned',
    ]);
});
