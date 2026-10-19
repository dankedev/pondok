<?php

use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config(['app.debug' => false]);

    Route::get('/_uji-galat/{code}', fn (int $code) => abort($code));
});

it('F067: renders Indonesian global error pages without technical details', function () {
    foreach ([403 => '403', 419 => '419', 429 => '429', 500 => '5xx', 502 => '5xx', 503 => '503', 405 => '4xx'] as $status => $key) {
        $content = $this->get("/_uji-galat/{$status}")->assertStatus($status)->getContent();

        expect($content)
            ->toContain('<html lang="id"')
            ->toContain('<meta name="robots" content="noindex">')
            ->toContain(e(__("pondok.http_errors.{$key}.title")))
            ->toContain(e(__("pondok.http_errors.{$key}.body")))
            ->not->toContain('HttpException')
            ->not->toContain('vendor/');
    }
});

it('F067: offers reload on 419, home on other errors, and no action during maintenance', function () {
    expect($this->get('/_uji-galat/419')->getContent())->toContain(__('pondok.http_errors.reload'))
        ->and($this->get('/_uji-galat/500')->getContent())->toContain(__('pondok.portal.errors.back_home'))
        ->and($this->get('/_uji-galat/503')->getContent())->not->toContain(__('pondok.portal.errors.back_home'));
});

it('F067: renders the 500 page even when the database is unavailable', function () {
    $default = config('database.default');
    config(['database.connections.mysql_rusak' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'x', 'username' => 'x', 'password' => 'x'], 'database.default' => 'mysql_rusak']);

    try {
        $this->get('/_uji-galat/500')->assertStatus(500)->assertSee(__('pondok.http_errors.5xx.title'));
    } finally {
        // Kembalikan koneksi agar transaksi RefreshDatabase bisa di-rollback.
        config(['database.default' => $default]);
    }
});
