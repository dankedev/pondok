<?php

use App\Models\Invoice;
use App\Models\Setting;
use Carbon\Carbon;
use Database\Seeders\SettingSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->seed(SettingSeeder::class);
    $this->f = tagihanFixture();
    tahunanFeeType($this->f);
    tagihanStudent($this->f);
});

it('AC-11: does nothing without options when the module is disabled', function () {
    Setting::set('modules.keuangan.enabled', false);
    $this->travelTo(Carbon::parse('2026-07-01 01:00:00', 'Asia/Jakarta'));

    $exit = Artisan::call('keuangan:generate-annual-invoices');

    expect($exit)->toBe(0)
        ->and(Invoice::withoutUnitScope()->count())->toBe(0);
});

it('AC-11: does nothing in scheduler mode when the billing month is not set', function () {
    Setting::set('keuangan.annual_billing_month', null);
    $this->travelTo(Carbon::parse('2026-07-01 01:00:00', 'Asia/Jakarta'));

    $exit = Artisan::call('keuangan:generate-annual-invoices');

    expect($exit)->toBe(0)
        ->and(Invoice::withoutUnitScope()->count())->toBe(0);
});

it('AC-11: does nothing in scheduler mode outside the billing month', function () {
    $this->travelTo(Carbon::parse('2026-10-01 01:00:00', 'Asia/Jakarta'));

    $exit = Artisan::call('keuangan:generate-annual-invoices');

    expect($exit)->toBe(0)
        ->and(Invoice::withoutUnitScope()->count())->toBe(0);
});

it('AC-11: bills the academic year of today in the billing month and prints a summary', function () {
    $this->travelTo(Carbon::parse('2026-07-01 01:00:00', 'Asia/Jakarta'));

    $exit = Artisan::call('keuangan:generate-annual-invoices');

    $invoice = Invoice::withoutUnitScope()->sole();

    expect($exit)->toBe(0)
        ->and($invoice->period->toDateString())->toBe('2026-07-01')
        ->and($invoice->academic_year_id)->toBe($this->f->year->id)
        ->and($invoice->generated_by)->toBeNull()
        ->and(Artisan::output())->not->toBe('')
        ->and(Artisan::output())->not->toContain('pondok.invoice.');
});

it('AC-11: fails when no academic year contains today', function () {
    Setting::set('keuangan.annual_billing_month', 7);
    $this->travelTo(Carbon::parse('2030-07-01 01:00:00', 'Asia/Jakarta'));

    $exit = Artisan::call('keuangan:generate-annual-invoices');

    expect($exit)->toBe(1)
        ->and(Invoice::withoutUnitScope()->count())->toBe(0);
});

it('AC-11: bills the given academic year even outside the billing month', function () {
    $this->travelTo(Carbon::parse('2026-10-15 09:00:00', 'Asia/Jakarta'));

    $exit = Artisan::call('keuangan:generate-annual-invoices', ['--academic-year' => '2026/2027']);

    expect($exit)->toBe(0)
        ->and(Invoice::withoutUnitScope()->sole()->period->toDateString())->toBe('2026-07-01');
});

it('AC-11: fails for an unknown academic year name', function () {
    $exit = Artisan::call('keuangan:generate-annual-invoices', ['--academic-year' => '1999/2000']);

    expect($exit)->toBe(1)
        ->and(Invoice::withoutUnitScope()->count())->toBe(0);
});

it('AC-11: prints the validation message and fails when the billing month is not set', function () {
    Setting::set('keuangan.annual_billing_month', null);

    $exit = Artisan::call('keuangan:generate-annual-invoices', ['--academic-year' => '2026/2027']);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain(__('pondok.invoice.errors.annual_month_not_set'))
        ->and(Invoice::withoutUnitScope()->count())->toBe(0);
});

it('AC-11: prints the validation message and fails when the generator is already running', function () {
    $lock = Cache::lock('keuangan:generate-invoices:2026-07', 60);
    expect($lock->get())->toBeTrue();

    $exit = Artisan::call('keuangan:generate-annual-invoices', ['--academic-year' => '2026/2027']);

    $lock->release();

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain(__('pondok.invoice.errors.already_running'))
        ->and(Invoice::withoutUnitScope()->count())->toBe(0);
});

it('AC-13: schedules the annual generator on the first of the month after the monthly generator', function () {
    $events = collect(app(Schedule::class)->events())->values();
    $named = fn (string $command): ?Event => $events->first(fn (Event $event): bool => str_contains($event->command ?? '', $command));

    $annual = $named('keuangan:generate-annual-invoices');
    $monthly = $named('keuangan:generate-invoices');

    expect($annual)->not->toBeNull()
        ->and($annual->expression)->toBe('0 1 1 * *')
        ->and((string) $annual->timezone)->toBe('Asia/Jakarta')
        ->and($annual->withoutOverlapping)->toBeTrue()
        ->and($events->search($annual))->toBeGreaterThan($events->search($monthly));
});
