<?php

use App\Models\Invoice;
use App\Models\Setting;
use Carbon\Carbon;
use Database\Seeders\SettingSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->seed(SettingSeeder::class);
    $this->f = tagihanFixture();
});

it('AC-39: generates invoices for the given period', function () {
    tagihanStudent($this->f, resident: true);
    tagihanStudent($this->f);

    $exit = Artisan::call('keuangan:generate-invoices', ['--period' => '2026-10']);

    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and(Invoice::withoutUnitScope()->count())->toBe(2)
        ->and(Invoice::withoutUnitScope()->pluck('generated_by')->filter()->all())->toBe([])
        ->and(Invoice::withoutUnitScope()->first()->period->toDateString())->toBe('2026-10-01')
        ->and($output)->not->toContain('pondok.invoice.')
        ->and($output)->toContain('2');
});

it('AC-39: prints a summary with the skipped count', function () {
    tagihanStudent($this->f);
    tagihanStudent($this->f, attributes: ['unit_id' => $this->f->slta->id], cohort: $this->f->cohortSlta);

    Artisan::call('keuangan:generate-invoices', ['--period' => '2026-10']);

    expect(__('pondok.invoice.command.summary'))->not->toBe('pondok.invoice.command.summary')
        ->and(Artisan::output())->not->toBe('')
        ->and(Artisan::output())->not->toContain('pondok.invoice.');
});

it('AC-40: defaults to the current month', function () {
    tagihanStudent($this->f);
    $this->travelTo(Carbon::parse('2026-10-01 01:00:00', 'Asia/Jakarta'));

    $exit = Artisan::call('keuangan:generate-invoices');

    expect($exit)->toBe(0)
        ->and(Invoice::withoutUnitScope()->sole()->period->toDateString())->toBe('2026-10-01');
});

it('AC-41: fails on an invalid period', function (string $period) {
    tagihanStudent($this->f);

    $exit = Artisan::call('keuangan:generate-invoices', ['--period' => $period]);

    expect($exit)->toBe(1)
        ->and(Invoice::withoutUnitScope()->count())->toBe(0);
})->with(['2026-13', 'oktober', '2026-1', '2026-10-01']);

it('AC-41: fails when no academic year covers the period', function () {
    tagihanStudent($this->f);

    $exit = Artisan::call('keuangan:generate-invoices', ['--period' => '2030-01']);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain(__('pondok.invoice.errors.no_academic_year'))
        ->and(Invoice::withoutUnitScope()->count())->toBe(0);
});

it('AC-42: does nothing when the module is disabled', function () {
    tagihanStudent($this->f);
    Setting::set('modules.keuangan.enabled', false);

    $exit = Artisan::call('keuangan:generate-invoices', ['--period' => '2026-10']);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain(__('pondok.invoice.command.module_disabled'))
        ->and(Invoice::withoutUnitScope()->count())->toBe(0);
});

it('AC-43: schedules the generator on the first of the month', function () {
    $events = collect(app(Schedule::class)->events());

    $event = $events->first(fn (Event $event): bool => str_contains($event->command ?? '', 'keuangan:generate-invoices'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 1 1 * *')
        ->and((string) $event->timezone)->toBe('Asia/Jakarta')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($events->contains(fn (Event $event): bool => str_contains($event->command ?? '', 'queue:work')))->toBeTrue();
});
