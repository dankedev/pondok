<?php

use App\Actions\Konten\GetSchoolProfile;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    kontenBoot();
});

it('AC-37: lists the school profile keys in one place', function () {
    expect(GetSchoolProfile::KEYS)->toContain(
        'school.name', 'school.short_name', 'school.arabic_name', 'school.tagline', 'school.logo_path',
        'school.logo_desktop_path', 'school.logo_mobile_path', 'school.favicon_path', 'school.address',
        'school.phone', 'school.email', 'school.map_url', 'school.contacts', 'school.social_links',
        'portal.hero_greeting', 'portal.hero_title', 'portal.hero_subtitle', 'portal.highlights_heading',
        'portal.highlights', 'portal.hero_album_id', 'portal.profile_video_id', 'portal.psb_video_id',
        'portal.footer_quote_arabic', 'portal.footer_quote_translation', 'portal.footer_quote_source',
        'portal.footer_description', 'portal.footer_links',
    )->not->toContain('school.whatsapp', 'school.instagram_url', 'school.facebook_url', 'school.youtube_url');
});

it('AC-1: reads the school profile with a single settings query', function () {
    profilSet([
        'school.name' => 'Pondok Uji', 'school.short_name' => 'PU', 'school.tagline' => 'Motto',
        'school.contacts' => [profilContact()], 'school.social_links' => [['platform' => 'instagram', 'label' => null, 'url' => 'https://instagram.com/x']],
        'portal.hero_title' => 'Judul', 'portal.highlights' => [['title' => 'A', 'description' => null]],
        'portal.footer_links' => [['label' => 'Berita', 'url' => '/berita']], 'school.logo_desktop_path' => 'school/d.png',
    ]);
    app()->forgetScopedInstances();

    DB::enableQueryLog();
    $profile = app(GetSchoolProfile::class)->handle();
    $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'settings'));

    expect($queries)->toHaveCount(1)
        ->and($profile['name'])->toBe('Pondok Uji')
        ->and($profile['short_name'])->toBe('PU')
        ->and($profile['hero']['title'])->toBe('Judul')
        ->and($profile['logo_desktop_url'])->toBe(Storage::disk('public')->url('school/d.png'));

    DB::flushQueryLog();
    app(GetSchoolProfile::class)->handle();

    expect(DB::getQueryLog())->toBe([]);
});

it('AC-2: returns empty defaults and no legacy keys', function () {
    Setting::query()->where('key', 'like', 'school.%')->delete();
    Setting::set('school.name', 'Pondok Uji');
    app()->forgetScopedInstances();

    $profile = app(GetSchoolProfile::class)->handle();

    expect($profile['name'])->toBe('Pondok Uji')
        ->and($profile)->not->toHaveKeys(['whatsapp', 'instagram_url', 'facebook_url', 'youtube_url'])
        ->and($profile['short_name'])->toBeNull()->and($profile['arabic_name'])->toBeNull()->and($profile['map_url'])->toBeNull()
        ->and($profile['logo_url'])->toBeNull()->and($profile['logo_desktop_url'])->toBeNull()
        ->and($profile['logo_mobile_url'])->toBeNull()->and($profile['favicon_url'])->toBeNull()
        ->and($profile['contacts'])->toBe([])->and($profile['social_links'])->toBe([])
        ->and($profile['hero'])->toBe(['greeting' => null, 'title' => null, 'subtitle' => null])
        ->and($profile['highlights_heading'])->toBeNull()->and($profile['highlights'])->toBe([])
        ->and($profile['hero_album_id'])->toBeNull()->and($profile['profile_video_id'])->toBeNull()->and($profile['psb_video_id'])->toBeNull()
        ->and($profile['footer'])->toBe(['quote_arabic' => null, 'quote_translation' => null, 'quote_source' => null, 'description' => null, 'links' => []]);
});

it('AC-3: skips malformed list items without an exception', function () {
    Setting::set('school.contacts', ['bukan item', 12, null, ['label' => 'Tanpa telepon', 'key' => 'zzzz9999'], profilContact(['phone' => '081200000009'])]);
    Setting::set('portal.highlights', ['teks', ['description' => 'tanpa judul'], ['title' => 'Sah', 'description' => null]]);
    app()->forgetScopedInstances();

    $profile = app(GetSchoolProfile::class)->handle();

    expect($profile['contacts'])->toHaveCount(1)
        ->and($profile['contacts'][0]['phone'])->toBe('081200000009')
        ->and($profile['contacts'][0]['label'])->toBe('Sekretariat')
        ->and($profile['highlights'])->toHaveCount(1)
        ->and($profile['highlights'][0]['title'])->toBe('Sah');
});

it('AC-3: treats a settings value that is not a list as empty', function () {
    Setting::set('school.contacts', 'bukan daftar');
    Setting::set('school.social_links', 42);
    app()->forgetScopedInstances();

    $profile = app(GetSchoolProfile::class)->handle();

    expect($profile['contacts'])->toBe([])->and($profile['social_links'])->toBe([]);
});

it('AC-4: drops unsafe urls on read and keeps the safe ones', function () {
    Setting::set('school.social_links', [
        ['platform' => 'instagram', 'label' => null, 'url' => 'javascript:alert(1)'],
        ['platform' => 'facebook', 'label' => null, 'url' => "https://a.test/\tx"],
        ['platform' => 'youtube', 'label' => null, 'url' => 'https://youtube.com/@aman'],
    ]);
    Setting::set('portal.footer_links', [
        ['label' => 'A', 'url' => '//evil.test'],
        ['label' => 'B', 'url' => "/\tevil"],
        ['label' => 'C', 'url' => '/\\evil.test'],
        ['label' => 'Berita', 'url' => '/berita'],
        ['label' => 'Luar', 'url' => 'https://contoh.test/x'],
    ]);
    Setting::set('school.map_url', 'javascript:x');
    app()->forgetScopedInstances();

    $profile = app(GetSchoolProfile::class)->handle();

    expect(array_column($profile['social_links'], 'url'))->toBe(['https://youtube.com/@aman'])
        ->and(array_column($profile['footer']['links'], 'url'))->toBe(['/berita', 'https://contoh.test/x'])
        ->and($profile['map_url'])->toBeNull();
});

it('AC-4: keeps a safe map url', function () {
    Setting::set('school.map_url', 'https://maps.app.goo.gl/abc');
    app()->forgetScopedInstances();

    expect(app(GetSchoolProfile::class)->handle()['map_url'])->toBe('https://maps.app.goo.gl/abc');
});

it('AC-30 (F037): reads page links tolerantly', function () {
    Setting::set('portal.footer_links', [['label' => 'A', 'url' => '/berita'], ['page_id' => 5], ['page_id' => 'x'], ['page_id' => -1], ['label' => 'Z', 'page_id' => 0]]);

    expect(app(GetSchoolProfile::class)->handle()['footer']['links'])->toBe([['label' => 'A', 'url' => '/berita'], ['label' => null, 'page_id' => 5]])
        ->and(GetSchoolProfile::KEYS)->toContain('portal.menu_items', 'portal.menu_show_register');
});
