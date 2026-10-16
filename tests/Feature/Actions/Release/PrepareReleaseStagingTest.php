<?php

use App\Actions\Release\PrepareReleaseStaging;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->base = f019TempDirectory('pondok-staging-');
    $this->repo = $this->base.'/repo';
    $this->staging = $this->base.'/staging';
    mkdir($this->repo);
    $this->ran = [];
});

afterEach(fn () => f019RemoveDirectory($this->base));

function fakeStagingProcesses(object $test, ?string $failing = null, string $error = ''): void
{
    Process::fake(function (PendingProcess $process) use ($test, $failing, $error) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;
        $test->ran[] = ['command' => $command, 'path' => $process->path];

        if (str_contains($command, 'git archive') && preg_match('/--output=(\S+)/', $command, $m)) {
            $zip = new ZipArchive;
            $zip->open($m[1], ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $zip->addFromString('artisan', '#!/usr/bin/env php');
            $zip->addFromString('composer.json', '{}');
            $zip->close();
        }

        if ($failing !== null && str_contains($command, $failing)) {
            return Process::result(output: '', errorOutput: $error, exitCode: 1);
        }

        return Process::result();
    });
}

it('AC-6: runs build steps in order with pnpm (repo ships pnpm-lock.yaml)', function () {
    fakeStagingProcesses($this);

    app(PrepareReleaseStaging::class)->handle($this->repo, $this->staging, '1.2.0');

    $commands = collect($this->ran)->pluck('command')->all();

    expect($commands)->toHaveCount(4)
        ->and($commands[0])->toContain('git archive')->toContain('HEAD')
        ->and($commands[1])->toContain('composer install')->toContain('--no-dev')->toContain('--optimize-autoloader')->toContain('--no-interaction')
        ->and($commands[2])->toContain('pnpm install')->toContain('--frozen-lockfile')->toContain('--ignore-workspace')
        ->and($commands[3])->toContain('pnpm run build')
        ->and(collect($commands)->contains(fn ($c) => preg_match('/(^|\s)npm\s/', $c) === 1))->toBeFalse()
        ->and($this->ran[0]['path'])->toBe($this->repo)
        ->and(collect($this->ran)->slice(1)->pluck('path')->unique()->all())->toBe([$this->staging])
        ->and($this->staging.'/artisan')->toBeFile()
        ->and(trim(file_get_contents($this->staging.'/VERSION')))->toBe('1.2.0')
        ->and(glob($this->staging.'.src.zip'))->toBe([]);
});

it('AC-7: stops at the first failing step and reports it', function () {
    fakeStagingProcesses($this, 'pnpm run build', 'vite error');

    expect(fn () => app(PrepareReleaseStaging::class)->handle($this->repo, $this->staging, '1.2.0'))
        ->toThrow(RuntimeException::class, 'vite error');

    expect($this->staging.'/VERSION')->not->toBeFile();
});

it('AC-7: a failing composer install prevents the asset build', function () {
    fakeStagingProcesses($this, 'composer install', 'composer gagal');

    expect(fn () => app(PrepareReleaseStaging::class)->handle($this->repo, $this->staging, '1.2.0'))
        ->toThrow(RuntimeException::class);

    expect(collect($this->ran)->contains(fn ($r) => str_contains($r['command'], 'pnpm')))->toBeFalse();
});
