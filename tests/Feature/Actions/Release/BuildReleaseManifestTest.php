<?php

use App\Actions\Release\BuildReleaseManifest;

beforeEach(function () {
    $this->root = f019TempDirectory('pondok-manifest-');
});

afterEach(fn () => f019RemoveDirectory($this->root));

it('AC-1: excludes development and runtime files', function () {
    releaseFixture($this->root, [...releaseRuntimePaths(), ...releaseExcludedPaths()]);

    $manifest = app(BuildReleaseManifest::class)->handle($this->root);

    expect(array_intersect($manifest, releaseExcludedPaths()))->toBe([]);
});

it('AC-2: includes runtime files and the storage skeleton, sorted with forward slashes', function () {
    releaseFixture($this->root, [...releaseRuntimePaths(), ...releaseExcludedPaths()]);

    $manifest = app(BuildReleaseManifest::class)->handle($this->root);

    expect(array_diff(releaseRuntimePaths(), $manifest))->toBe([])
        ->and($manifest)->toBe(collect($manifest)->sort(SORT_STRING)->values()->all())
        ->and(collect($manifest)->contains(fn ($p) => str_starts_with($p, './') || str_contains($p, '\\')))->toBeFalse();
});

it('AC-3: does not follow or list symlinks', function () {
    $outside = f019TempDirectory('pondok-outside-');
    file_put_contents($outside.'/secret.txt', 'rahasia');
    file_put_contents($outside.'/x.php', 'x');

    releaseFixture($this->root, releaseRuntimePaths());
    symlink($outside, $this->root.'/public/storage');
    symlink($outside.'/x.php', $this->root.'/app/link.php');

    $manifest = app(BuildReleaseManifest::class)->handle($this->root);
    f019RemoveDirectory($outside);

    expect(collect($manifest)->contains(fn ($p) => str_starts_with($p, 'public/storage') || $p === 'app/link.php' || str_contains($p, 'secret.txt')))->toBeFalse();
});

it('AC-4: reports missing and forbidden paths', function () {
    $action = app(BuildReleaseManifest::class);
    $complete = releaseRuntimePaths();

    expect($action->missingRequirements($complete))->toBe([]);

    $without = fn (string $path) => $action->missingRequirements(array_values(array_diff($complete, [$path])));

    expect(implode("\n", $without('public/build/manifest.json')))->toContain('public/build/manifest.json')
        ->and(implode("\n", $without('vendor/autoload.php')))->toContain('vendor/autoload.php')
        ->and(implode("\n", $without('public/js/filament/app.js')))->toContain('public/js/filament')
        ->and(implode("\n", $without('.htaccess')))->toContain('.htaccess')
        ->and(implode("\n", $without('.env.production.example')))->toContain('.env.production.example')
        ->and(implode("\n", $action->missingRequirements([...$complete, 'vendor/pestphp/pest/bin/pest'])))->toContain('vendor/pestphp/')
        ->and(implode("\n", $action->missingRequirements([...$complete, '.env.staging'])))->toContain('.env.staging');
});

it('AC-4: the repository ships a root htaccess that denies all access', function () {
    expect(base_path('.htaccess'))->toBeFile()
        ->and(file_get_contents(base_path('.htaccess')))->toContain('Require all denied')->toContain('Deny from all');
});

it('AC-4: public htaccess explicitly allows access so the root deny is not inherited', function () {
    expect(file_get_contents(base_path('public/.htaccess')))->toMatch('/Require all granted/')
        ->and(file_get_contents(base_path('public/.htaccess')))->toMatch('/Allow from all/');
});
