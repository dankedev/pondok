<?php

use App\Actions\Release\WriteReleaseArchive;

beforeEach(function () {
    $this->root = f019TempDirectory('pondok-archive-');
    $this->out = f019TempDirectory('pondok-archive-out-');
    releaseFixture($this->root, ['artisan', 'public/build/manifest.json', 'app/a.php', 'app/b.php']);
});

afterEach(function () {
    f019RemoveDirectory($this->root);
    f019RemoveDirectory($this->out);
});

it('AC-5: writes a prefixed zip with checksum', function () {
    $manifest = ['app/a.php', 'app/b.php', 'artisan', 'public/build/manifest.json'];
    $zip = $this->out.'/pondok-1.2.0.zip';

    $result = app(WriteReleaseArchive::class)->handle($this->root, $manifest, $zip);

    $entries = zipEntries($zip);
    $archive = new ZipArchive;
    $archive->open($zip);

    expect($entries)->toHaveCount(count($manifest))
        ->and(collect($entries)->every(fn ($e) => str_starts_with($e, 'pondok/')))->toBeTrue()
        ->and($archive->getFromName('pondok/public/build/manifest.json'))->toBe(file_get_contents($this->root.'/public/build/manifest.json'))
        ->and($result['sha256'])->toBe(hash_file('sha256', $zip))
        ->and($result['files'])->toBe(count($manifest))
        ->and(file_get_contents($zip.'.sha256'))->toBe($result['sha256'].'  pondok-1.2.0.zip'."\n");
});

it('AC-5: replaces an existing archive instead of appending', function () {
    $zip = $this->out.'/pondok-1.2.0.zip';
    $action = app(WriteReleaseArchive::class);

    $action->handle($this->root, ['app/a.php', 'app/b.php', 'artisan'], $zip);
    $action->handle($this->root, ['artisan'], $zip);

    expect(zipEntries($zip))->toBe(['pondok/artisan']);
});
