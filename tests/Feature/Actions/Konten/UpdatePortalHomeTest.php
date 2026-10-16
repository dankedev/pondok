<?php

use App\Actions\Konten\UpdatePortalHome;
use App\Models\Setting;

beforeEach(function () {
    $this->f = kontenBoot();
    $this->admin = kontenUser('admin-konten');
});

/**
 * @param  array<string, mixed>  $data
 */
function profilSaveHome(array $data): void
{
    app(UpdatePortalHome::class)->handle($data, test()->admin);
}

it('AC-26: saves the greeting, highlights and media', function () {
    $album = kontenAlbum(['title' => 'Fasilitas']);
    $profileVideo = kontenVideo(['title' => 'Profil']);
    $psbVideo = kontenVideo(['title' => 'PSB']);

    profilSaveHome([
        'hero_greeting' => '  Sambutan uji  ', 'hero_title' => 'Judul uji', 'hero_subtitle' => 'Subjudul uji',
        'highlights_heading' => 'Judul keunggulan',
        'highlights' => [
            ['title' => 'Satu', 'description' => 'Deskripsi satu'],
            ['title' => 'Dua', 'description' => ''],
            ['title' => 'Tiga'],
        ],
        'hero_album_id' => (string) $album->id, 'profile_video_id' => $profileVideo->id, 'psb_video_id' => (string) $psbVideo->id,
    ]);

    expect(Setting::get('portal.hero_greeting'))->toBe('Sambutan uji')
        ->and(Setting::get('portal.hero_title'))->toBe('Judul uji')
        ->and(Setting::get('portal.hero_subtitle'))->toBe('Subjudul uji')
        ->and(Setting::get('portal.highlights_heading'))->toBe('Judul keunggulan')
        ->and(array_column(Setting::get('portal.highlights'), 'title'))->toBe(['Satu', 'Dua', 'Tiga'])
        ->and(Setting::get('portal.highlights')[1]['description'])->toBeNull()
        ->and(Setting::get('portal.hero_album_id'))->toBe($album->id)
        ->and(Setting::get('portal.profile_video_id'))->toBe($profileVideo->id)
        ->and(Setting::get('portal.psb_video_id'))->toBe($psbVideo->id)
        ->and(kontenActivities()->where('description', 'portal_home_updated'))->toHaveCount(1);
});

it('AC-26: stores blank text and an empty list as null and does not log unchanged data', function () {
    profilSaveHome(['hero_title' => 'Judul', 'highlights' => [['title' => 'A']]]);
    profilSaveHome(['hero_title' => 'Judul', 'highlights' => [['title' => 'A']]]);

    expect(kontenActivities()->where('description', 'portal_home_updated'))->toHaveCount(1);

    profilSaveHome(['hero_title' => '', 'highlights' => []]);

    expect(Setting::get('portal.hero_title'))->toBeNull()->and(Setting::get('portal.highlights'))->toBeNull();
});

it('AC-27: rejects a missing album or video and changes nothing', function (array $input, string $reason, string $field) {
    profilSaveHome(['hero_title' => 'Lama']);
    $before = profilSettingsSnapshot();

    kontenAssertRejected(fn () => profilSaveHome(['hero_title' => 'Baru', ...$input]), $reason, $field);

    expect(profilSettingsSnapshot())->toBe($before);
})->with([
    'album' => [['hero_album_id' => 9999], 'album_not_found', 'hero_album_id'],
    'profile video' => [['profile_video_id' => 9999], 'video_not_found', 'profile_video_id'],
    'psb video' => [['psb_video_id' => 9999], 'video_not_found', 'psb_video_id'],
]);

it('AC-28: rejects nine highlights, a highlight without a title and too long text', function (array $input, string $field) {
    profilSaveHome(['hero_title' => 'Lama']);
    $before = profilSettingsSnapshot();

    profilAssertInvalid(fn () => profilSaveHome($input), $field);

    expect(profilSettingsSnapshot())->toBe($before);
})->with([
    'nine' => [['highlights' => array_map(fn (int $i): array => ['title' => "K{$i}"], range(1, 9))], 'highlights'],
    'no title' => [['highlights' => [['title' => '', 'description' => 'x']]], 'highlights.0.title'],
    'missing title' => [['highlights' => [['description' => 'x']]], 'highlights.0.title'],
    'title 81' => [['highlights' => [['title' => str_repeat('a', 81)]]], 'highlights.0.title'],
    'description 301' => [['highlights' => [['title' => 'a', 'description' => str_repeat('a', 301)]]], 'highlights.0.description'],
    'hero title 151' => [['hero_title' => str_repeat('a', 151)], 'hero_title'],
    'hero subtitle 301' => [['hero_subtitle' => str_repeat('a', 301)], 'hero_subtitle'],
    'greeting 101' => [['hero_greeting' => str_repeat('a', 101)], 'hero_greeting'],
    'heading 101' => [['highlights_heading' => str_repeat('a', 101)], 'highlights_heading'],
]);

it('AC-28: accepts exactly eight highlights', function () {
    profilSaveHome(['highlights' => array_map(fn (int $i): array => ['title' => "K{$i}"], range(1, 8))]);

    expect(Setting::get('portal.highlights'))->toHaveCount(8);
});

it('AC-29: keeps the media when their keys are absent and clears one only when it is null', function () {
    $album = kontenAlbum();
    $video = kontenVideo();
    profilSaveHome(['hero_album_id' => $album->id, 'profile_video_id' => $video->id, 'psb_video_id' => $video->id]);

    profilSaveHome(['hero_title' => 'Baru']);

    expect(Setting::get('portal.hero_album_id'))->toBe($album->id)
        ->and(Setting::get('portal.profile_video_id'))->toBe($video->id)
        ->and(Setting::get('portal.psb_video_id'))->toBe($video->id);

    profilSaveHome(['hero_title' => 'Baru', 'hero_album_id' => null]);

    expect(Setting::get('portal.hero_album_id'))->toBeNull()
        ->and(Setting::get('portal.profile_video_id'))->toBe($video->id)
        ->and(Setting::get('portal.psb_video_id'))->toBe($video->id);
});

it('AC-29: still replaces the text fields in full when only some keys are sent', function () {
    profilSaveHome(['hero_greeting' => 'Sambutan', 'hero_title' => 'Judul']);

    profilSaveHome(['hero_title' => 'Judul baru']);

    expect(Setting::get('portal.hero_greeting'))->toBeNull()->and(Setting::get('portal.hero_title'))->toBe('Judul baru');
});
