<?php

use App\Providers\AppServiceProvider;

beforeEach(fn () => $this->iso = isolateInstallation());

afterEach(fn () => $this->iso->finish());

it('AC-27: uses the configured public path', function () {
    $tmp = $this->iso->tmp;
    config(['pondok.public_path' => $tmp.'/other/']);

    (new AppServiceProvider(app()))->register();

    expect(public_path())->toBe($tmp.'/other')
        ->and(public_path('build/manifest.json'))->toBe($tmp.'/other/build/manifest.json');
});

it('AC-27: keeps the default public path when nothing is configured', function () {
    $app = app();
    $app->usePublicPath(base_path('public'));
    config(['pondok.public_path' => null]);

    (new AppServiceProvider($app))->register();

    expect(public_path())->toBe(base_path('public'));
});

it('AC-27: config wires the public disk root and links to env', function () {
    $filesystems = file_get_contents(base_path('config/filesystems.php'));

    expect($filesystems)->toContain('PUBLIC_STORAGE_ROOT')->toContain('PONDOK_PUBLIC_PATH')
        ->and(file_get_contents(base_path('config/pondok.php')))->toContain('PONDOK_PUBLIC_PATH');
});
