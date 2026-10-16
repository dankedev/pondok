<?php

use App\Services\QrCodeRenderer;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

it('AC-17: renders an inline html5 svg', function () {
    $svg = app(QrCodeRenderer::class)->svg('https://pondok.test/verifikasi-surat/ABCDEFGHJKMN', 'QR verifikasi surat 001');

    expect(trim($svg))->toStartWith('<svg')
        ->and($svg)->not->toContain('<?xml')
        ->not->toContain('xmlns')
        ->not->toContain('http')
        ->not->toContain('data:')
        ->not->toContain('<script')
        ->not->toContain('#fff')
        ->not->toContain('#ffffff');

    preg_match('/<svg\b[^>]*>/i', $svg, $tag);

    expect($tag[0])->toContain('role="img"')->toContain('aria-label="QR verifikasi surat 001"');
});

it('AC-17: draws a black code on a transparent background', function () {
    $svg = strtolower(app(QrCodeRenderer::class)->svg('https://pondok.test/verifikasi-surat/ABCDEFGHJKMN', 'QR'));

    expect($svg)->toContain('<path')->toMatch('/#000(000)?\b/');
});

it('AC-17: escapes the label', function () {
    $svg = app(QrCodeRenderer::class)->svg('https://pondok.test/x', 'a"<b>');

    preg_match('/<svg\b[^>]*>/i', $svg, $tag);

    expect($tag[0])->toContain('aria-label="a')
        ->not->toContain('a"<b>')
        ->not->toContain('<b>')
        ->and(substr_count($svg, '<svg'))->toBe(1);
});

it('AC-17: uses medium error correction and a quiet zone', function () {
    $options = app(QrCodeRenderer::class)->options();

    expect($options)->toBeInstanceOf(QROptions::class)
        ->and($options->eccLevel)->toBe(QRCode::ECC_M)
        ->and($options->addQuietzone)->toBeTrue()
        ->and($options->outputBase64)->toBeFalse()
        ->and($options->drawLightModules)->toBeFalse();
});

it('AC-17: encodes the verification url', function () {
    if (! extension_loaded('gd')) {
        $this->markTestSkipped('GD tidak tersedia.');
    }

    $url = 'https://pondok.test/verifikasi-surat/ABCDEFGHJKMN';
    $options = app(QrCodeRenderer::class)->options(QROutputInterface::GDIMAGE_PNG);
    $png = (new QRCode($options))->render($url);

    expect((string) (new QRCode)->readFromBlob($png))->toBe($url);
});

it('AC-42: keeps the qr library installed', function () {
    expect(class_exists(QRCode::class))->toBeTrue()
        ->and(class_exists(QROptions::class))->toBeTrue();
});
