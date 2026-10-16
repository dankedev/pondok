<?php

use App\Actions\Core\PreserveCpanelHtaccessBlocks;

const F019_HANDLER = "# php -- BEGIN cPanel-generated handler, do not edit\n<IfModule mime_module>\n  AddHandler application/x-httpd-ea-php83 .php .php8 .phtml\n</IfModule>\n# php -- END cPanel-generated handler, do not edit";

beforeEach(function () {
    $this->iso = isolateInstallation();
    $this->tmp = $this->iso->tmp;
    $this->path = $this->tmp.'/web/.htaccess';
});

afterEach(fn () => $this->iso->finish());

it('AC-25b: remembers and restores cpanel blocks', function () {
    $action = app(PreserveCpanelHtaccessBlocks::class);
    file_put_contents($this->path, "RewriteEngine On\n\n".F019_HANDLER."\n");

    expect($action->remember($this->path))->toBe(1)
        ->and(file_get_contents(storage_path('framework/pondok-cpanel-htaccess')))->toContain('ea-php83');

    file_put_contents($this->path, "RewriteEngine On\nRewriteRule ^ index.php [L]\n");

    expect($action->restore($this->path))->toBe(1)
        ->and(substr_count(file_get_contents($this->path), 'cPanel-generated handler, do not edit'))->toBe(2)
        ->and(file_get_contents($this->path))->toContain('RewriteRule ^ index.php [L]');

    $after = file_get_contents($this->path);

    expect($action->restore($this->path))->toBe(0)
        ->and(file_get_contents($this->path))->toBe($after);
});

it('AC-25b: an htaccess without blocks keeps the earlier remembered copy', function () {
    $action = app(PreserveCpanelHtaccessBlocks::class);
    file_put_contents($this->path, F019_HANDLER."\n");
    $action->remember($this->path);

    file_put_contents($this->path, "RewriteEngine On\n");

    expect($action->remember($this->path))->toBe(0)
        ->and(file_get_contents(storage_path('framework/pondok-cpanel-htaccess')))->toContain('ea-php83');
});

it('AC-25b: ignores unterminated blocks and merge is idempotent', function () {
    $action = app(PreserveCpanelHtaccessBlocks::class);

    expect($action->extract("# php -- BEGIN cPanel-generated handler, do not edit\nAddHandler x .php\n"))->toBe([]);

    $blocks = $action->extract("aturan lama\n".F019_HANDLER."\n");
    $merged = $action->merge("RewriteEngine On\n", $blocks);

    expect($blocks)->toHaveCount(1)
        ->and($action->merge($merged, $blocks))->toBe($merged)
        ->and(substr_count($merged, 'ea-php83'))->toBe(1);
});

it('AC-25b (review #2): skips a remembered block when the file already has a block of the same kind', function () {
    $action = app(PreserveCpanelHtaccessBlocks::class);
    file_put_contents($this->path, F019_HANDLER."\n");
    $action->remember($this->path);

    file_put_contents($this->path, str_replace('ea-php83', 'ea-php84', F019_HANDLER)."\n");

    expect($action->restore($this->path))->toBe(0)
        ->and(file_get_contents($this->path))->toContain('ea-php84')->not->toContain('ea-php83');
});
