<?php

use App\Actions\Core\CheckSystemHealth;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\Process;

it('AC-48: production env example is complete', function () {
    $env = Dotenv::parse(file_get_contents(base_path('.env.production.example')));
    $dev = Dotenv::parse(file_get_contents(base_path('.env.example')));

    expect($env['APP_ENV'])->toBe('production')
        ->and($env['APP_DEBUG'])->toBe('false')
        ->and($env['APP_KEY'] ?? null)->toBeIn(['', null])
        ->and($env['APP_URL'])->toStartWith('https://')
        ->and($env['APP_LOCALE'])->toBe('id')
        ->and($env['LOG_LEVEL'])->toBe('error')
        ->and($env['DB_CONNECTION'])->toBe('mysql')
        ->and($env['QUEUE_CONNECTION'])->toBe('database')
        ->and($env['CACHE_STORE'])->toBe('database')
        ->and($env['SESSION_DRIVER'])->toBe('database')
        ->and($env['FILESYSTEM_DISK'])->toBe('local')
        ->and($env['SUPER_ADMIN_PASSWORD'] ?? null)->toBeIn(['', null])
        ->and(array_diff(array_keys($dev), array_keys($env)))->toBe([])
        ->and(collect(array_keys($env))->contains(fn ($k) => str_starts_with($k, 'REDIS_')))->toBeFalse()
        ->and(file_get_contents(base_path('.env.production.example')))->toContain('TRUSTED_PROXIES')->toContain('PONDOK_PUBLIC_PATH')->toContain('PUBLIC_STORAGE_ROOT')
        ->and($dev['APP_ENV'])->toBe('local')->and($dev['APP_DEBUG'])->toBe('true');
});

it('AC-48: .gitignore does not ignore the production example', function () {
    expect(Process::path(base_path())->run(['git', 'check-ignore', '-q', '.env.production.example'])->exitCode())->not->toBe(0);
});

it('AC-49: installation guide covers cpanel', function () {
    $guide = file_get_contents(base_path('docs/instalasi.md'));

    foreach (['pondok:install', 'pondok:doctor', 'pondok:upgrade', 'pondok:publish-public', 'schedule:run', 'storage:link', 'PUBLIC_STORAGE_ROOT', 'PONDOK_PUBLIC_PATH', 'APP_URL', 'TRUSTED_PROXIES', 'public/build', 'ea-php83', '8.3', 'MySQL', 'MariaDB', 'public_html', 'proc_open', 'MultiPHP', 'cPanel-generated handler', '.htaccess', '403', 'peran bawaan'] as $needle) {
        expect($guide)->toContain($needle);
    }

    expect(strtolower($guide))->toContain('backup');

    foreach (CheckSystemHealth::REQUIRED_EXTENSIONS as $extension) {
        expect($guide)->toContain($extension);
    }

    expect(file_get_contents(base_path('docs/rilis.md')))->toContain('pondok:release')->toContain('--no-dev');
});

it('AC-49: readme and gitignore are updated', function () {
    expect(file_get_contents(base_path('README.md')))->toContain('docs/instalasi.md')->not->toContain('About Laravel')
        ->and(file($path = base_path('.gitignore'), FILE_IGNORE_NEW_LINES))->toContain('/VERSION');
});
