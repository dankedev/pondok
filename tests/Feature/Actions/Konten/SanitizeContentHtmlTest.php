<?php

use App\Actions\Konten\SanitizeContentHtml;

it('AC-13: strips scripts, event handlers, javascript urls and iframes', function () {
    $html = app(SanitizeContentHtml::class)->handle('<p>Halo</p><script>alert(1)</script><img src="x" onerror="alert(1)"><a href="javascript:alert(1)">klik</a><iframe src="https://evil.test"></iframe>');

    expect($html)->toContain('<p>Halo</p>')->toContain('klik')
        ->not->toContain('<script')->not->toContain('onerror')->not->toContain('javascript:')->not->toContain('<iframe');
});

it('AC-13: returns an empty string for null', function () {
    expect(app(SanitizeContentHtml::class)->handle(null))->toBe('');
});

it('AC-13: treats empty markup as blank', function (string $html, bool $blank) {
    expect(app(SanitizeContentHtml::class)->isBlank($html))->toBe($blank);
})->with([
    'spaces only' => ['<p> </p>', true],
    'line break' => ['<p><br></p>', true],
    'empty string' => ['', true],
    'nbsp entity' => ['<p>&nbsp;</p>', true],
    'text' => ['<p>Halo</p>', false],
    'image only' => ['<p><img src="/a.jpg"></p>', false],
]);

it('AC-13b: detects media sources that are not relative, http or https', function (string $html, bool $disallowed) {
    $action = app(SanitizeContentHtml::class);

    expect($action->hasDisallowedMediaSource($action->handle($html)))->toBe($disallowed);
})->with([
    'svg data uri' => ['<img src="data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=">', true],
    'png data uri' => ['<img src="data:image/png;base64,iVBORw0KGgo=">', true],
    'srcset with data candidate' => ['<img src="/a.jpg" srcset="/a.jpg 1x, data:image/png;base64,AAA 2x">', true],
    'https' => ['<img src="https://example.test/a.jpg" alt="a">', false],
    'http' => ['<img src="http://example.test/a.jpg">', false],
    'relative' => ['<img src="/storage/a.jpg">', false],
    'plain text mentioning data' => ['<p>src="data:image/png;base64,AAA"</p>', false],
    'no media' => ['<p>Halo</p>', false],
]);

it('AC-13b: drops data urls from link hrefs', function () {
    $html = app(SanitizeContentHtml::class)->handle('<a href="data:text/html;base64,AAA">x</a>');

    expect($html)->not->toContain('data:')->toContain('x');
});

it('AC-13c: keeps style and class attributes', function () {
    $html = app(SanitizeContentHtml::class)->handle('<p style="color: red" class="lead">x</p>');

    expect($html)->toContain('style="color: red"')->toContain('class="lead"');
});

it('AC-13a: exposes the byte limit and keeps multibyte text intact below it', function () {
    expect(SanitizeContentHtml::MAX_INPUT_BYTES)->toBe(500000);

    $html = app(SanitizeContentHtml::class)->handle(kontenArabicBody(200000));

    expect(strip_tags($html))->toBe(str_repeat('ب', 200000));
});
