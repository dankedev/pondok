<?php

use App\Actions\Keuangan\PruneFinanceExports;
use App\Enums\Module;
use App\Models\Setting;
use Database\Seeders\SettingSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('private');
});

function exportFile(string $path, int $ageHours): string
{
    Storage::disk('private')->put($path, 'csv');
    touch(Storage::disk('private')->path($path), now()->subHours($ageHours)->getTimestamp());

    return $path;
}

it('AC-12: deletes exports older than 24 hours with their folder and keeps the rest', function () {
    $old = exportFile('finance-exports/1/aaaa/laporan.csv', 25);
    $recent = exportFile('finance-exports/1/bbbb/laporan.csv', 1);
    $proof = exportFile('payment-proofs/2026/10/bukti.jpg', 100);
    $dotfile = exportFile('finance-exports/.gitkeep', 100);

    expect(app(PruneFinanceExports::class)->handle())->toBe(1);

    Storage::disk('private')->assertMissing($old);
    Storage::disk('private')->assertExists([$recent, $proof, $dotfile]);
    expect(Storage::disk('private')->directoryExists('finance-exports/1/aaaa'))->toBeFalse()
        ->and(PruneFinanceExports::RETENTION_HOURS)->toBe(24);
});

it('AC-12: returns zero when there is no export directory', function () {
    expect(app(PruneFinanceExports::class)->handle())->toBe(0);
});

it('AC-12: the command prunes and reports the number of deleted files', function () {
    exportFile('finance-exports/1/aaaa/laporan.csv', 30);

    $this->artisan('keuangan:prune-finance-exports')->assertSuccessful()->expectsOutputToContain('1');

    expect(Storage::disk('private')->allFiles('finance-exports'))->toBe([]);
});

it('AC-13: schedules the command hourly and skips it while the keuangan module is off', function () {
    $this->seed(SettingSeeder::class);
    Artisan::call('schedule:list');

    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $e): bool => str_contains((string) $e->command, 'keuangan:prune-finance-exports'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *')
        ->and($event->filtersPass(app()))->toBeTrue();

    Setting::set('modules.'.Module::Keuangan->value.'.enabled', false);

    expect($event->filtersPass(app()))->toBeFalse();
});
