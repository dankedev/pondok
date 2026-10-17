<?php

use App\Actions\Konten\AddAlbumPhotos;
use App\Actions\Konten\DeletePost;
use App\Actions\Konten\RestorePost;
use App\Actions\Konten\SavePost;
use App\Actions\Konten\UpdateSchoolContacts;
use App\Actions\Konten\UpdateSchoolProfile;
use App\Models\Post;
use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->f = kontenBoot();
    $this->editor = kontenUser('admin-konten');
    $this->admin = kontenUser('admin-sekolah');
});

/**
 * @param  array<string, mixed>  $overrides
 */
function kontenGapSave(array $overrides = [], ?Post $post = null): Post
{
    return app(SavePost::class)->handle(kontenPostData($overrides), test()->editor, $post);
}

/**
 * Gambar sungguhan (isi dikenali finfo) dengan nama berkas tertentu.
 */
function kontenRealImage(string $name = 'a.jpg', string $format = 'jpeg'): UploadedFile
{
    $image = imagecreatetruecolor(8, 8);
    ob_start();
    match ($format) {
        'png' => imagepng($image),
        'gif' => imagegif($image),
        'webp' => imagewebp($image),
        default => imagejpeg($image),
    };
    $content = (string) ob_get_clean();

    return pendaftaranRealFile($name, $content);
}

it('VERIFY: rejects data media sources pasted in disguised forms', function (string $body) {
    kontenAssertRejected(fn () => kontenGapSave(['body' => $body]), 'media_source_invalid', 'body');

    expect(Post::query()->count())->toBe(0);
})->with([
    'uppercase scheme' => '<p>x</p><img src="DATA:image/png;base64,AAA">',
    'entity encoded scheme' => '<p>x</p><img src="&#100;ata:image/png;base64,AAA">',
    'video poster' => '<p>x</p><video poster="data:image/png;base64,AAA" src="/x.mp4"></video>',
    'srcset first candidate' => '<p>x</p><img srcset="data:image/png;base64,AAA 1x">',
    'srcset later candidate' => '<p>x</p><img src="/a.jpg" srcset="/b.jpg 1x,data:image/png;base64,AAA 2x">',
]);

it('VERIFY: never stores executable or dangerous markup from a paste', function (string $body, string $forbidden) {
    $post = kontenGapSave(['body' => '<p>aman</p>'.$body]);

    expect($post->fresh()->body)->toContain('aman')->not->toContain($forbidden);
})->with([
    'entity encoded javascript link' => ['<a href="&#106;avascript:alert(1)">x</a>', 'javascript'],
    'tab split javascript image' => ['<img src="jav&#x09;ascript:alert(1)">', 'javascript'],
    'mixed case javascript link' => ['<a href="JaVaScRiPt:alert(1)">x</a>', 'script:'],
    'leading space data image' => ['<img src=" data:image/png;base64,AAA">', 'data:'],
    'svg block' => ['<svg><script>alert(1)</script></svg>', '<svg'],
    'form' => ['<form action="https://evil.test"><input name="x"></form>', '<form'],
    'object and embed' => ['<object data="data:text/html,x"></object><embed src="data:text/html,x">', '<embed'],
    'style block' => ['<style>body{display:none}</style>', '<style'],
    'base tag' => ['<base href="https://evil.test">', '<base'],
    'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.test">', '<meta'],
    'unquoted handler' => ['<img src="x" onerror=alert(1)//>', 'onerror'],
    'math link' => ['<math><mi xlink:href="javascript:alert(1)">x</mi></math>', 'javascript'],
]);

it('VERIFY: keeps escaped script text as text', function () {
    $post = kontenGapSave(['body' => '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>']);

    expect($post->fresh()->body)->toContain('&lt;script&gt;')->not->toContain('<script');
});

it('VERIFY: accepts a body of exactly 500000 bytes without truncating it', function () {
    $body = '<p>'.str_repeat('ب', 249996).'a</p>';

    expect(strlen($body))->toBe(500000);

    $post = kontenGapSave(['body' => $body]);

    expect(strip_tags($post->fresh()->body))->toBe(str_repeat('ب', 249996).'a');
});

it('VERIFY: rejects html, svg, php and gif files that pretend to be images', function (string $name, string $content) {
    $cover = fn () => pendaftaranRealFile($name, $content);

    kontenAssertRejected(fn () => kontenGapSave(['cover' => $cover()]), 'image_invalid', 'cover');
    kontenAssertRejected(fn () => app(UpdateSchoolProfile::class)->handle(['name' => 'X', 'logo' => $cover()], test()->admin), 'image_invalid', 'logo');
    kontenAssertRejected(fn () => app(AddAlbumPhotos::class)->handle(kontenAlbum(), [$cover()]), 'image_invalid', 'photos');

    expect(Post::query()->count())->toBe(0)->and(kontenFiles())->toBe([]);
})->with([
    'svg named png' => ['a.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
    'xml svg named jpg' => ['a.jpg', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"></svg>'],
    'html named jpg' => ['a.jpg', '<html><body><script>alert(1)</script></body></html>'],
    'php named jpg' => ['a.jpg', '<?php echo "x";'],
    'pdf named png' => ['a.png', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"],
    'empty file' => ['a.jpg', ''],
]);

it('VERIFY: rejects gif and webp content even with a jpg name', function (string $format) {
    kontenAssertRejected(fn () => kontenGapSave(['cover' => kontenRealImage('a.jpg', $format)]), 'image_invalid', 'cover');

    expect(kontenFiles())->toBe([]);
})->with(['gif', 'webp']);

it('VERIFY: names stored images by content type and a random name, never by the original name', function () {
    $post = kontenGapSave(['cover' => kontenRealImage('shell.php', 'png')]);
    $traversal = kontenGapSave(['title' => 'Dua', 'cover' => kontenRealImage('../../evil.jpg', 'jpeg')]);

    foreach ([$post->fresh()->cover_path => 'png', $traversal->fresh()->cover_path => 'jpg'] as $path => $extension) {
        expect($path)->toMatch('#^posts/covers/[0-9a-f-]{36}\.'.$extension.'$#')
            ->and(Storage::disk('public')->exists($path))->toBeTrue();
    }
});

it('VERIFY: gives photos with the same original name distinct random paths', function () {
    $album = kontenAlbum();

    $photos = app(AddAlbumPhotos::class)->handle($album, [kontenImage('sama.jpg'), kontenImage('sama.jpg'), kontenImage('sama.jpg')]);

    expect($photos->pluck('path')->unique())->toHaveCount(3)
        ->and($photos->every(fn ($photo): bool => preg_match("#^albums/{$album->id}/[0-9a-f-]{36}\\.jpg$#", $photo->path) === 1))->toBeTrue();
});

it('VERIFY: keeps new photos working when the sort order is at its maximum', function () {
    $album = kontenAlbum();
    kontenPhoto($album, ['sort_order' => 32767]);

    $photos = app(AddAlbumPhotos::class)->handle($album, [kontenImage('1.jpg'), kontenImage('2.jpg')]);

    expect($photos)->toHaveCount(2)->and($photos->pluck('sort_order')->max())->toBeLessThanOrEqual(32767);
});

it('VERIFY: restores a soft-deleted post unchanged and keeps its slug reserved meanwhile', function () {
    $post = kontenGapSave(['status' => 'terbit', 'published_at' => '2026-10-01 07:00', 'cover' => kontenImage()]);
    $cover = $post->fresh()->cover_path;

    app(DeletePost::class)->handle($post);

    expect(Post::published()->count())->toBe(0)
        ->and(kontenGapSave()->slug)->toBe('wisuda-tahfidz-2026-2');

    app(RestorePost::class)->handle($post->fresh());

    expect($post->fresh())->slug->toBe('wisuda-tahfidz-2026')->cover_path->toBe($cover)->deleted_at->toBeNull()
        ->and($post->fresh()->published_at->format('Y-m-d H:i'))->toBe('2026-10-01 07:00')
        ->and(Post::published()->pluck('id')->all())->toBe([$post->id])
        ->and(Storage::disk('public')->exists($cover))->toBeTrue();
});

it('VERIFY: clears the youtube link when it is emptied on update', function () {
    $post = kontenGapSave(['youtube_url' => 'https://youtu.be/dQw4w9WgXcQ']);

    kontenGapSave(['youtube_url' => ''], $post);

    expect($post->fresh()->youtube_url)->toBeNull();
});

it('VERIFY: rejects a profile update as a whole when only the logo is invalid', function () {
    app(UpdateSchoolProfile::class)->handle(['name' => 'Lama'], $this->admin);
    Setting::set('school.address', 'Alamat lama');
    $before = kontenActivities()->count();

    kontenAssertRejected(fn () => app(UpdateSchoolProfile::class)->handle(['name' => 'Baru', 'address' => 'Baru', 'logo' => kontenPdf('a.pdf')], $this->admin), 'image_invalid', 'logo');

    expect(Setting::get('school.name'))->toBe('Lama')->and(Setting::get('school.address'))->toBe('Alamat lama')
        ->and(kontenActivities()->count())->toBe($before)->and(kontenFiles())->toBe([]);
});

it('VERIFY: validates phone numbers strictly', function (string $phone, bool $valid) {
    $call = fn () => app(UpdateSchoolContacts::class)->handle(['contacts' => [['label' => 'X', 'phone' => $phone]]], $this->admin);

    if ($valid) {
        $call();

        expect(Setting::get('school.contacts'))->toHaveCount(1);

        return;
    }

    kontenAssertRejected($call, 'phone_invalid', 'contacts.0.phone');
})->with([
    'eight digits' => ['12345678', true],
    'fifteen digits' => ['123456789012345', true],
    'seven digits' => ['1234567', false],
    'sixteen digits' => ['1234567890123456', false],
    'letters inside' => ['0812abc3456789', false],
    'plus inside' => ['0812+3456789', false],
    'double plus' => ['++628123456789', false],
]);

it('VERIFY: rejects social links with spaces, quotes or non-http schemes', function (string $url) {
    kontenAssertRejected(fn () => app(UpdateSchoolContacts::class)->handle(['social_links' => [['platform' => 'facebook', 'url' => $url]]], $this->admin), 'url_invalid', 'social_links.0.url');
})->with([
    'space' => ['https://x.test/a b'],
    'javascript' => ['javascript://x.test/%0aalert(1)'],
    'mailto' => ['mailto:a@b.test'],
    'no scheme' => ['facebook.com/pondok'],
]);

it('VERIFY: rejects a social link longer than 255 characters', function () {
    kontenAssertRejected(fn () => app(UpdateSchoolContacts::class)->handle(['social_links' => [['platform' => 'youtube', 'url' => 'https://x.test/'.str_repeat('a', 250)]]], $this->admin), 'too_long', 'social_links.0.url');
});

it('VERIFY: rejects an email longer than 255 characters', function () {
    kontenAssertRejected(fn () => app(UpdateSchoolContacts::class)->handle(['email' => str_repeat('a', 251).'@b.id'], $this->admin), 'too_long', 'email');
});
