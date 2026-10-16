<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->out = f019TempDirectory('pondok-release-out-');
    $this->stagingBefore = glob(sys_get_temp_dir().'/pondok-release-*') ?: [];
});

afterEach(function () {
    f019RemoveDirectory($this->out);
});

/**
 * @param  list<string>  $archivePaths
 */
function fakeReleaseProcesses(array $archivePaths, string $status = '', string $describe = "v1.3.0\n"): void
{
    Process::fake(function (PendingProcess $process) use ($archivePaths, $status, $describe) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        if (str_contains($command, 'git status')) {
            return Process::result(output: $status);
        }

        if (str_contains($command, 'git describe')) {
            return Process::result(output: $describe);
        }

        if (str_contains($command, 'git archive') && preg_match('/--output=(\S+)/', $command, $m)) {
            $zip = new ZipArchive;
            $zip->open($m[1], ZipArchive::CREATE | ZipArchive::OVERWRITE);

            foreach ($archivePaths as $path) {
                $zip->addFromString($path, 'isi:'.$path);
            }

            $zip->close();
        }

        return Process::result();
    });
}

function releaseArchivePaths(): array
{
    return array_values(array_diff(releaseRuntimePaths(), ['VERSION']));
}

function releaseCall(array $args): array
{
    $exit = Artisan::call('pondok:release', $args);

    return [$exit, Artisan::output()];
}

it('AC-8: builds a release zip end to end', function () {
    fakeReleaseProcesses(releaseArchivePaths());

    [$exit, $output] = releaseCall(['--release-version' => '1.2.0', '--output' => $this->out]);

    $entries = zipEntries($this->out.'/pondok-1.2.0.zip');

    expect($exit)->toBe(0)
        ->and($this->out.'/pondok-1.2.0.zip.sha256')->toBeFile()
        ->and($entries)->toContain('pondok/public/build/manifest.json', 'pondok/vendor/autoload.php', 'pondok/VERSION', 'pondok/.env.example', 'pondok/.env.production.example', 'pondok/.htaccess')
        ->and(collect($entries)->contains(fn ($e) => str_starts_with($e, 'pondok/node_modules/') || str_starts_with($e, 'pondok/tests/') || str_starts_with($e, 'pondok/.git/') || $e === 'pondok/.env'))->toBeFalse()
        ->and($output)->toContain($this->out.'/pondok-1.2.0.zip')->not->toContain('pondok.release.')
        ->and(array_diff(glob(sys_get_temp_dir().'/pondok-release-*') ?: [], $this->stagingBefore))->toBe([]);

    Process::assertNotRan(fn (PendingProcess $p) => str_contains(is_array($p->command) ? implode(' ', $p->command) : $p->command, 'git describe'));
});

it('AC-9: uses git describe for the default version', function () {
    fakeReleaseProcesses(releaseArchivePaths());

    [$exit] = releaseCall(['--output' => $this->out]);

    $zip = new ZipArchive;
    $zip->open($this->out.'/pondok-v1.3.0.zip');

    expect($exit)->toBe(0)
        ->and(trim($zip->getFromName('pondok/VERSION')))->toBe('v1.3.0');
});

it('AC-10: refuses in production without running any process', function () {
    Process::fake();
    app()['env'] = 'production';

    [$exit] = releaseCall(['--release-version' => '1.2.0', '--output' => $this->out]);

    expect($exit)->toBe(1);
    Process::assertNothingRan();
});

it('AC-10: refuses a dirty tree unless allowed', function () {
    fakeReleaseProcesses(releaseArchivePaths(), ' M app/x.php');

    [$exit] = releaseCall(['--release-version' => '1.2.0', '--output' => $this->out]);

    expect($exit)->toBe(1);
    Process::assertNotRan(fn (PendingProcess $p) => str_contains(is_array($p->command) ? implode(' ', $p->command) : $p->command, 'git archive'));

    fakeReleaseProcesses(releaseArchivePaths(), ' M app/x.php');

    [$exit, $output] = releaseCall(['--release-version' => '1.2.0', '--output' => $this->out, '--allow-dirty' => true]);

    expect($exit)->toBe(0)->and($output)->not->toBe('')->and($output)->not->toContain('pondok.release.');
});

it('AC-10: rejects an unsafe version', function () {
    fakeReleaseProcesses(releaseArchivePaths());

    [$exit] = releaseCall(['--release-version' => '../x', '--output' => $this->out]);

    expect($exit)->toBe(1);
    Process::assertNotRan(fn (PendingProcess $p) => str_contains(is_array($p->command) ? implode(' ', $p->command) : $p->command, 'git archive'));
});

it('AC-10: refuses an incomplete package and writes no zip', function () {
    fakeReleaseProcesses(array_values(array_diff(releaseArchivePaths(), ['public/build/manifest.json'])));

    [$exit, $output] = releaseCall(['--release-version' => '1.2.0', '--output' => $this->out]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('public/build/manifest.json')
        ->and(glob($this->out.'/*.zip') ?: [])->toBe([]);
});
