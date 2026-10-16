<?php

use App\Actions\Konten\ParseYoutubeId;

it('AC-14: parses supported youtube inputs', function (string $input) {
    expect(app(ParseYoutubeId::class)->handle($input))->toBe('dQw4w9WgXcQ');
})->with([
    'raw id' => 'dQw4w9WgXcQ',
    'padded raw id' => '  dQw4w9WgXcQ  ',
    'watch' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'watch with extras' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10',
    'watch without www' => 'https://youtube.com/watch?v=dQw4w9WgXcQ',
    'http watch' => 'http://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'mobile watch' => 'https://m.youtube.com/watch?v=dQw4w9WgXcQ',
    'music watch' => 'https://music.youtube.com/watch?v=dQw4w9WgXcQ',
    'short link' => 'https://youtu.be/dQw4w9WgXcQ?si=abc',
    'shorts' => 'https://m.youtube.com/shorts/dQw4w9WgXcQ',
    'embed' => 'https://www.youtube.com/embed/dQw4w9WgXcQ',
    'live' => 'https://www.youtube.com/live/dQw4w9WgXcQ',
    'nocookie embed' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
]);

it('AC-14: keeps a raw id with dash and underscore as it is', function () {
    expect(app(ParseYoutubeId::class)->handle('a-b_c-d_e-f'))->toBe('a-b_c-d_e-f');
});

it('AC-14: returns null for blank input', function (?string $input) {
    expect(app(ParseYoutubeId::class)->handle($input))->toBeNull();
})->with([null, '', '   ']);

it('AC-14: rejects non-youtube or malformed input', function (string $input) {
    expect(fn () => app(ParseYoutubeId::class)->handle($input))->toThrow(InvalidArgumentException::class);
})->with([
    'vimeo' => 'https://vimeo.com/123',
    'short id' => 'abc',
    'long raw id' => 'dQw4w9WgXcQx',
    'short id in watch' => 'https://www.youtube.com/watch?v=abc',
    'javascript scheme' => 'javascript:alert(1)',
    'lookalike host' => 'https://youtube.com.evil.test/watch?v=dQw4w9WgXcQ',
    'host as path' => 'https://evil.test/youtube.com/watch?v=dQw4w9WgXcQ',
    'ftp scheme' => 'ftp://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'raw id with symbol' => 'dQw4w9WgXc!',
    'watch without id' => 'https://www.youtube.com/watch',
    'channel path' => 'https://www.youtube.com/@pondok',
]);

it('AC-14: builds the normalized watch url', function () {
    expect(ParseYoutubeId::watchUrl('dQw4w9WgXcQ'))->toBe('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
});
