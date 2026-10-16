<?php

use App\Actions\Konten\FormatPhoneForDisplay;
use App\Actions\Konten\SummarizeHtml;

it('AC-34: formats phone numbers', function (?string $input, ?string $expected) {
    expect(app(FormatPhoneForDisplay::class)->handle($input))->toBe($expected);
})->with([
    ['081234567890', '0812-3456-7890'],
    ['+6281234567890', '+62 812-3456-7890'],
    ['0215551234', '0215-5512-34'],
    [null, null],
    ['', null],
]);

it('AC-34: builds whatsapp urls', function (string $input, string $expected) {
    expect(app(FormatPhoneForDisplay::class)->whatsappUrl($input))->toBe($expected);
})->with([
    ['081234567890', 'https://wa.me/6281234567890'],
    ['+6281234567890', 'https://wa.me/6281234567890'],
]);

it('AC-34: summarizes html', function (?string $html, string $expected) {
    expect(app(SummarizeHtml::class)->handle($html, 160))->toBe($expected);
})->with([
    ['<p>A&amp;B</p>  <p>C</p>', 'A&B C'],
    ['<p>Halo</p><p>Dunia</p>', 'Halo Dunia'],
    ['Satu<br>Dua', 'Satu Dua'],
    ['<ul><li>Satu</li><li>Dua</li></ul>', 'Satu Dua'],
    ['Halo<strong>,</strong> dunia', 'Halo, dunia'],
    ['Ba<b>ca</b>', 'Baca'],
    ['<p>&nbsp;</p>', ''],
    [null, ''],
]);

it('AC-34: limits summaries to 160 characters without breaking utf-8', function () {
    $summary = app(SummarizeHtml::class)->handle('<p>'.str_repeat('ب', 200).'</p>', 160);

    expect(mb_strlen($summary))->toBe(161)
        ->and($summary)->toEndWith('…')
        ->and(mb_check_encoding($summary, 'UTF-8'))->toBeTrue();
});
