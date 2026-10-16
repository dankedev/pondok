<?php

use Illuminate\Support\Facades\Artisan;

beforeEach(fn () => $this->iso = isolateInstallation());

afterEach(fn () => $this->iso->finish());

it('AC-28: requires a target path', function () {
    config(['pondok.public_path' => null]);

    $exit = Artisan::call('pondok:publish-public');

    expect($exit)->toBe(1)->and(Artisan::output())->toContain('PONDOK_PUBLIC_PATH')->not->toContain('pondok.publish_public.');
});

it('AC-28: reports a missing target without an exception', function () {
    $exit = Artisan::call('pondok:publish-public', ['--path' => $this->iso->tmp.'/tidak-ada']);

    expect($exit)->toBe(1)->and(Artisan::output())->not->toContain('RuntimeException')->not->toContain('pondok.publish_public.');
});
