<?php

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

const LEGACY_KEYS = ['school.whatsapp', 'school.instagram_url', 'school.facebook_url', 'school.youtube_url'];

it('AC-5: moves whatsapp and social urls into lists', function () {
    profilRawSet('school.whatsapp', '0812-0000-0170');
    profilRawSet('school.instagram_url', 'https://instagram.com/alkhair');
    profilRawSet('school.youtube_url', 'https://youtube.com/@alkhair');

    profilMigration()->up();

    $row = DB::table('settings')->where('key', 'school.contacts')->first();
    $contacts = json_decode((string) $row->value, true);

    expect($contacts)->toBeArray()->toHaveCount(1)
        ->and($contacts[0]['label'])->toBe('WhatsApp')
        ->and($contacts[0]['phone'])->toBe('081200000170')
        ->and($contacts[0]['is_whatsapp'])->toBeTrue()
        ->and($contacts[0]['key'])->toMatch('/^[a-z0-9]{8}$/')
        ->and($row->created_at)->not->toBeNull()->and($row->updated_at)->not->toBeNull();

    $socialRow = DB::table('settings')->where('key', 'school.social_links')->first();
    $socials = collect(json_decode((string) $socialRow->value, true))->map(fn (array $link): array => ['platform' => $link['platform'], 'url' => $link['url']])->all();

    expect($socials)->toBe([
        ['platform' => 'instagram', 'url' => 'https://instagram.com/alkhair'],
        ['platform' => 'youtube', 'url' => 'https://youtube.com/@alkhair'],
    ])->and($socialRow->created_at)->not->toBeNull()->and($socialRow->updated_at)->not->toBeNull()
        ->and(DB::table('settings')->whereIn('key', LEGACY_KEYS)->count())->toBe(0);
});

it('AC-6: merges the legacy whatsapp number into existing contacts only when it is new', function (string $legacy, int $expectedCount) {
    profilRawSet('school.contacts', [
        profilContact(['key' => 'aaaa1111', 'label' => 'Sekretariat', 'phone' => '081200000170']),
        profilContact(['key' => 'bbbb2222', 'label' => 'Panitia', 'phone' => '081299998888', 'is_whatsapp' => false]),
    ]);
    profilRawSet('school.whatsapp', $legacy);

    profilMigration()->up();

    $contacts = profilRawGet('school.contacts');

    expect($contacts)->toHaveCount($expectedCount)
        ->and(array_column($contacts, 'key'))->toContain('aaaa1111', 'bbbb2222')
        ->and(profilRawHas('school.whatsapp'))->toBeFalse();

    if ($expectedCount === 3) {
        expect(end($contacts)['phone'])->toBe('081311112222')->and(end($contacts)['label'])->toBe('WhatsApp');
    }
})->with([
    'same number with spaces' => ['0812 0000 0170', 2],
    'new number' => ['081311112222', 3],
]);

it('AC-7: skips unsafe legacy urls and does not duplicate existing ones', function () {
    profilRawSet('school.social_links', [['platform' => 'instagram', 'label' => null, 'url' => 'https://instagram.com/alkhair']]);
    profilRawSet('school.instagram_url', 'https://instagram.com/alkhair');
    profilRawSet('school.facebook_url', 'ftp://x');
    profilRawSet('school.youtube_url', 'javascript:alert(1)');

    profilMigration()->up();

    expect(array_column(profilRawGet('school.social_links'), 'url'))->toBe(['https://instagram.com/alkhair'])
        ->and(DB::table('settings')->whereIn('key', LEGACY_KEYS)->count())->toBe(0);
});

it('AC-7: does not move a legacy url that has a tab inside', function () {
    profilRawSet('school.facebook_url', "https://a.test/\tx");

    profilMigration()->up();

    expect(profilRawGet('school.social_links') ?? [])->toBe([])->and(profilRawHas('school.facebook_url'))->toBeFalse();
});

it('AC-8: copies the print logo into the desktop logo', function () {
    Storage::disk('public')->put('school/cetak.png', 'isi-logo-cetak');
    profilRawSet('school.logo_path', 'school/cetak.png');

    profilMigration()->up();

    $desktop = profilRawGet('school.logo_desktop_path');

    expect($desktop)->toStartWith('school/')->not->toBe('school/cetak.png')
        ->and(Storage::disk('public')->get($desktop))->toBe('isi-logo-cetak')
        ->and(profilRawGet('school.logo_path'))->toBe('school/cetak.png')
        ->and(Storage::disk('public')->exists('school/cetak.png'))->toBeTrue();
});

it('AC-8: keeps an existing desktop logo and tolerates a missing print logo file', function () {
    Storage::disk('public')->put('school/cetak.png', 'cetak');
    Storage::disk('public')->put('school/lama.png', 'lama');
    profilRawSet('school.logo_path', 'school/cetak.png');
    profilRawSet('school.logo_desktop_path', 'school/lama.png');

    profilMigration()->up();

    expect(profilRawGet('school.logo_desktop_path'))->toBe('school/lama.png')
        ->and(Storage::disk('public')->allFiles())->toHaveCount(2);

    DB::table('settings')->where('key', 'school.logo_desktop_path')->delete();
    Storage::disk('public')->delete('school/cetak.png');

    profilMigration()->up();

    expect(profilRawHas('school.logo_desktop_path'))->toBeFalse()->and(profilRawGet('school.logo_path'))->toBe('school/cetak.png');
});

it('AC-9: is idempotent', function () {
    Storage::disk('public')->put('school/cetak.png', 'cetak');
    profilRawSet('school.logo_path', 'school/cetak.png');
    profilRawSet('school.whatsapp', '0812-0000-0170');
    profilRawSet('school.instagram_url', 'https://instagram.com/alkhair');

    profilMigration()->up();
    $first = collect(profilSettingsSnapshot())->except([])->all();
    $files = Storage::disk('public')->allFiles();

    profilMigration()->up();

    expect(profilSettingsSnapshot())->toBe($first)
        ->and(Storage::disk('public')->allFiles())->toBe($files)
        ->and(profilRawGet('school.contacts'))->toHaveCount(1)
        ->and(profilRawGet('school.social_links'))->toHaveCount(1);
});

it('AC-9: ignores legacy values of the wrong type or broken lists without throwing', function () {
    profilRawSet('school.whatsapp', '0812-0000-0170');
    profilRawSet('school.contacts', 'RAW:{bukan json');
    profilRawSet('school.instagram_url', ['bukan', 'string']);
    profilRawSet('school.youtube_url', 'RAW:{rusak');

    profilMigration()->up();

    $contacts = profilRawGet('school.contacts');

    expect($contacts)->toBeArray()->toHaveCount(1)->and($contacts[0]['phone'])->toBe('081200000170')
        ->and(DB::table('settings')->whereIn('key', LEGACY_KEYS)->count())->toBe(0)
        ->and(profilRawGet('school.social_links') ?? [])->toBe([]);
});

it('AC-10: down restores the legacy keys and keeps the new data', function () {
    Storage::disk('public')->put('school/cetak.png', 'cetak');
    profilRawSet('school.logo_path', 'school/cetak.png');
    profilRawSet('school.whatsapp', '0812-0000-0170');
    profilRawSet('school.instagram_url', 'https://instagram.com/alkhair');
    profilRawSet('school.youtube_url', 'https://youtube.com/@alkhair');
    profilMigration()->up();
    $desktop = profilRawGet('school.logo_desktop_path');

    profilMigration()->down();

    expect(profilRawGet('school.whatsapp'))->toBe('081200000170')
        ->and(profilRawGet('school.instagram_url'))->toBe('https://instagram.com/alkhair')
        ->and(profilRawGet('school.youtube_url'))->toBe('https://youtube.com/@alkhair')
        ->and(profilRawHas('school.facebook_url'))->toBeFalse()
        ->and(profilRawGet('school.logo_desktop_path'))->toBe($desktop);
});

it('AC-10: down restores the first whatsapp contact and the first url per platform', function () {
    profilRawSet('school.contacts', [
        profilContact(['key' => 'aaaa1111', 'phone' => '081200000001', 'is_whatsapp' => false]),
        profilContact(['key' => 'bbbb2222', 'phone' => '081200000002']),
        profilContact(['key' => 'cccc3333', 'phone' => '081200000003']),
    ]);
    profilRawSet('school.social_links', [
        ['platform' => 'tiktok', 'label' => null, 'url' => 'https://tiktok.com/@x'],
        ['platform' => 'facebook', 'label' => null, 'url' => 'https://facebook.com/a'],
        ['platform' => 'facebook', 'label' => null, 'url' => 'https://facebook.com/b'],
    ]);

    profilMigration()->down();

    expect(profilRawGet('school.whatsapp'))->toBe('081200000002')
        ->and(profilRawGet('school.facebook_url'))->toBe('https://facebook.com/a')
        ->and(profilRawHas('school.instagram_url'))->toBeFalse();
});

it('AC-10: down never throws and creates no whatsapp for empty or broken contacts', function (mixed $stored) {
    if ($stored !== 'ABSENT') {
        profilRawSet('school.contacts', $stored);
    }

    profilMigration()->down();

    expect(profilRawHas('school.whatsapp'))->toBeFalse();
})->with([
    'absent' => ['ABSENT'],
    'null' => [null],
    'empty list' => [[]],
    'broken json' => ['RAW:{rusak'],
    'a string' => ['bukan daftar'],
    'items without whatsapp' => [[['key' => 'aaaa1111', 'label' => 'X', 'phone' => '081200000001', 'is_whatsapp' => false]]],
    'malformed items' => [['string', 12, ['phone' => null]]],
]);

it('AC-11: creates nothing without legacy keys or logo', function () {
    Setting::set('school.name', 'Pondok Uji');
    $before = DB::table('settings')->orderBy('key')->pluck('value', 'key')->all();

    profilMigration()->up();

    expect(DB::table('settings')->orderBy('key')->pluck('value', 'key')->all())->toBe($before)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});
