<?php

use App\Actions\Core\LinkPublicStorage;
use App\Services\SystemProbe;
use Tests\Fixtures\FakeSystemProbe;

beforeEach(function () {
    $this->iso = isolateInstallation();
    $this->tmp = $this->iso->tmp;
    config([
        'filesystems.disks.public.root' => $this->tmp.'/app-public',
        'filesystems.links' => [$this->tmp.'/web/storage' => $this->tmp.'/app-public'],
    ]);
    mkdir($this->tmp.'/app-public');
});

afterEach(fn () => $this->iso->finish());

it('AC-22: links public storage and is idempotent', function () {
    $result = app(LinkPublicStorage::class)->handle();

    expect($result['status'])->toBe('linked')
        ->and(is_link($this->tmp.'/web/storage'))->toBeTrue()
        ->and(realpath($this->tmp.'/web/storage'))->toBe(realpath($this->tmp.'/app-public'))
        ->and(app(LinkPublicStorage::class)->handle()['status'])->toBe('already_linked');
});

it('AC-23: reports disabled symlink without creating anything', function () {
    $probe = new FakeSystemProbe;
    $probe->disabledFunctions = ['symlink'];
    app()->instance(SystemProbe::class, $probe);

    $result = app(LinkPublicStorage::class)->handle();

    expect($result['status'])->toBe('symlink_unavailable')
        ->and(file_exists($this->tmp.'/web/storage') || is_link($this->tmp.'/web/storage'))->toBeFalse()
        ->and($result['suggestion'])->toBe('PUBLIC_STORAGE_ROOT='.$this->tmp.'/web/storage');
});

it('AC-24: supports a direct public root', function () {
    config(['filesystems.disks.public.root' => $this->tmp.'/web/storage']);

    $result = app(LinkPublicStorage::class)->handle();

    expect($result['status'])->toBe('direct')
        ->and(is_dir($this->tmp.'/web/storage'))->toBeTrue()
        ->and(is_link($this->tmp.'/web/storage'))->toBeFalse();
});

it('AC-24: does not overwrite a conflicting path', function () {
    file_put_contents($this->tmp.'/web/storage', 'berkas biasa');

    $result = app(LinkPublicStorage::class)->handle();

    expect($result['status'])->toBe('conflict')
        ->and(file_get_contents($this->tmp.'/web/storage'))->toBe('berkas biasa');
});
