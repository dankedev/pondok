<?php

use App\Services\SystemProbe;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\FakeSystemProbe;

beforeEach(function () {
    $this->iso = isolateInstallation();
    $this->probe = new FakeSystemProbe;
    app()->instance(SystemProbe::class, $this->probe);
    app()['env'] = 'production';
    config(['app.debug' => false, 'app.url' => 'https://pondok.sch.id', 'mail.default' => 'smtp', 'queue.default' => 'database', 'cache.default' => 'database', 'session.driver' => 'database']);
    symlink($this->iso->tmp.'/storage/app/public', $this->iso->tmp.'/web/storage');
    file_put_contents(storage_path('framework/pondok-scheduler-heartbeat'), (string) now()->subMinute()->timestamp);
});

afterEach(fn () => $this->iso->finish());

it('AC-29: exits 0 with translated labels on a healthy server', function () {
    $exit = Artisan::call('pondok:doctor');

    expect($exit)->toBe(0)->and(Artisan::output())->toContain(__('pondok.doctor.status.ok'))->not->toContain('pondok.doctor.');
});

it('AC-30: exits 1 on old php', function () {
    $this->probe->version = '8.2.20';

    expect(Artisan::call('pondok:doctor'))->toBe(1);
});

it('AC-33: a broken database exits 1 without a stack trace', function () {
    config(['database.default' => 'broken', 'database.connections.broken' => ['driver' => 'sqlite', 'database' => '/nonexistent-dir/x.sqlite', 'prefix' => '']]);

    $exit = Artisan::call('pondok:doctor');

    expect($exit)->toBe(1)->and(Artisan::output())->not->toContain('#0 ')->not->toContain('PDOException');
});
