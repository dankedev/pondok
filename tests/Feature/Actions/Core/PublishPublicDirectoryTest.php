<?php

use App\Actions\Core\PublishPublicDirectory;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->tmp = f019TempDirectory('pondok-publish-');
    $this->src = $this->tmp.'/src';
    $this->target = $this->tmp.'/public_html';
    $this->base = $this->tmp.'/pondok';
    mkdir($this->base);

    releaseFixture($this->src, ['index.php', '.htaccess', 'favicon.ico', 'robots.txt', 'hot', 'storage/x.jpg', 'build/manifest.json', 'build/assets/app-new.js', 'js/filament/app.js']);
    file_put_contents($this->src.'/.htaccess', "RewriteEngine On\nRewriteRule ^ index.php [L]\n");
    releaseFixture($this->target, ['cgi-bin/keep.sh', '.well-known/acme', 'build/assets/app-old.js']);
    file_put_contents($this->target.'/index.php', "<?php // pondok:public-index\n");
});

afterEach(fn () => f019RemoveDirectory($this->tmp));

function f019Snapshot(string $dir): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

    foreach ($it as $file) {
        $files[str_replace($dir, '', $file->getPathname())] = md5_file($file->getPathname());
    }

    ksort($files);

    return $files;
}

it('AC-25: copies public assets and writes a loadable index.php', function () {
    $result = app(PublishPublicDirectory::class)->handle($this->src, $this->target, $this->base);

    $index = file_get_contents($this->target.'/index.php');

    expect($result['copied'])->toBeGreaterThan(0)
        ->and(file_get_contents($this->target.'/build/assets/app-new.js'))->toBe('isi:build/assets/app-new.js')
        ->and(file_get_contents($this->target.'/robots.txt'))->toBe('isi:robots.txt')
        ->and(file_get_contents($this->target.'/js/filament/app.js'))->toBe('isi:js/filament/app.js')
        ->and(file_get_contents($this->target.'/.htaccess'))->toBe(file_get_contents($this->src.'/.htaccess'))
        ->and($this->target.'/build/assets/app-old.js')->not->toBeFile()
        ->and($this->target.'/cgi-bin/keep.sh')->toBeFile()
        ->and($this->target.'/.well-known/acme')->toBeFile()
        ->and($this->target.'/hot')->not->toBeFile()
        ->and($this->target.'/storage')->not->toBeDirectory()
        ->and($index)->toContain('pondok:public-index')->toContain(var_export($this->base, true))->toContain('usePublicPath(__DIR__)')->toContain('/vendor/autoload.php')->toContain('/bootstrap/app.php');

    expect(Process::run([PHP_BINARY, '-l', $this->target.'/index.php'])->successful())->toBeTrue();
});

it('AC-25a: keeps cpanel htaccess blocks exactly once', function () {
    $handler = "# php -- BEGIN cPanel-generated handler, do not edit\n<IfModule mime_module>\n  AddHandler application/x-httpd-ea-php83 .php .php8 .phtml\n</IfModule>\n# php -- END cPanel-generated handler, do not edit";
    $ini = "# BEGIN cPanel-generated php ini directives, do not edit\n<IfModule php_module>\n  php_value memory_limit 256M\n</IfModule>\n# END cPanel-generated php ini directives, do not edit";
    file_put_contents($this->target.'/.htaccess', "RewriteRule ^lama$ /lama [L]\n\n{$handler}\n\n{$ini}\n");

    $action = app(PublishPublicDirectory::class);
    $action->handle($this->src, $this->target, $this->base);
    $action->handle($this->src, $this->target, $this->base);

    $result = file_get_contents($this->target.'/.htaccess');

    expect($result)->toContain('RewriteRule ^ index.php [L]')
        ->and(substr_count($result, $handler))->toBe(1)
        ->and(substr_count($result, $ini))->toBe(1)
        ->and($result)->not->toContain('^lama$');
});

it('AC-26: refuses unsafe targets without touching them', function (string $case) {
    [$src, $target, $base] = match ($case) {
        'index.php tanpa penanda' => [$this->src, $this->target, $this->base],
        'target tidak ada' => [$this->src, $this->tmp.'/tidak-ada', $this->base],
        'target sama dengan source' => [$this->src, $this->src, $this->base],
        'source tanpa manifest' => [$this->src, $this->target, $this->base],
        'target sama dengan basePath' => [$this->src, $this->base, $this->base],
        'target induk basePath' => [$this->src, $this->tmp, $this->base],
    };

    if ($case === 'index.php tanpa penanda') {
        file_put_contents($this->target.'/index.php', '<?php // buatan orang lain');
    }

    if ($case === 'source tanpa manifest') {
        unlink($this->src.'/build/manifest.json');
    }

    $before = is_dir($target) ? f019Snapshot($target) : null;

    expect(fn () => app(PublishPublicDirectory::class)->handle($src, $target, $base))->toThrow(RuntimeException::class);

    expect(is_dir($target) ? f019Snapshot($target) : null)->toBe($before);
})->with(['index.php tanpa penanda', 'target tidak ada', 'target sama dengan source', 'source tanpa manifest', 'target sama dengan basePath', 'target induk basePath']);

it('AC-26: force overwrites a foreign index.php', function () {
    file_put_contents($this->target.'/index.php', '<?php // buatan orang lain');

    app(PublishPublicDirectory::class)->handle($this->src, $this->target, $this->base, true);

    expect(file_get_contents($this->target.'/index.php'))->toContain('pondok:public-index');
});
