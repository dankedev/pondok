<?php

use App\Actions\Konten\GetSchoolProfile;
use App\Actions\Konten\UpdateSchoolProfile;
use App\Models\Setting;
use Database\Seeders\Demo\DemoData;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->f = kontenBoot();
    $this->admin = kontenUser('admin-sekolah');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function kontenProfileData(array $overrides = []): array
{
    return [
        'name' => "Ma'had Al-Kitab",
        'tagline' => '',
        'address' => 'Jl. Pesantren No. 1',
        'phone' => '0812-3456-7890',
        'whatsapp' => '+62 812 3456 7890',
        'email' => 'info@pondokcontoh.sch.id',
        'instagram_url' => 'https://instagram.com/pondokcontoh',
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function kontenUpdateProfile(array $overrides = []): void
{
    app(UpdateSchoolProfile::class)->handle(kontenProfileData($overrides), test()->admin);
}

it('AC-33: writes the identity keys and no longer writes address, phone or email (F044)', function () {
    kontenUpdateProfile();

    expect(Setting::get('school.name'))->toBe("Ma'had Al-Kitab")
        ->and(Setting::get('school.phone'))->toBeNull()
        ->and(Setting::get('school.whatsapp'))->toBeNull()
        ->and(Setting::get('school.email'))->toBeNull()
        ->and(Setting::get('school.address'))->toBeNull()
        ->and(Setting::get('school.instagram_url'))->toBeNull()
        ->and(Setting::get('school.tagline'))->toBeNull()
        ->and(Setting::get('school.facebook_url'))->toBeNull()
        ->and(Setting::get('school.youtube_url'))->toBeNull();
});

it('AC-5: identity save with or without address, phone and email leaves address, email and contacts untouched', function (bool $withContactFields) {
    $contacts = [profilContact(['key' => 'aaaa1111', 'is_print' => true])];
    Setting::set('school.address', 'Alamat tersimpan');
    Setting::set('school.email', 'lama@pondokcontoh.sch.id');
    Setting::set('school.contacts', $contacts);
    $payload = ['name' => 'Nama Baru'];

    if ($withContactFields) {
        $payload += ['address' => 'Alamat dari form', 'phone' => '0812-3456-7890', 'email' => 'bukan-email'];
    }

    app(UpdateSchoolProfile::class)->handle($payload, $this->admin);

    expect(Setting::get('school.name'))->toBe('Nama Baru')
        ->and(Setting::get('school.address'))->toBe('Alamat tersimpan')
        ->and(Setting::get('school.email'))->toBe('lama@pondokcontoh.sch.id')
        ->and(Setting::get('school.contacts'))->toBe($contacts)
        ->and(Setting::get('school.phone'))->toBeNull();
})->with(['without contact fields' => [false], 'with contact fields (ignored, even invalid)' => [true]]);

it('AC-33: clears an optional key when it is emptied', function () {
    kontenUpdateProfile(['tagline' => 'Motto lama']);

    expect(Setting::get('school.tagline'))->toBe('Motto lama');

    kontenUpdateProfile(['tagline' => '']);

    expect(Setting::get('school.tagline'))->toBeNull()
        ->and(Setting::query()->where('key', 'school.tagline')->exists())->toBeTrue();
});

it('AC-34: rejects invalid input and leaves every key unchanged', function (array $overrides, string $reason, string $field) {
    kontenUpdateProfile(['name' => 'Nama Lama', 'address' => 'Alamat lama']);
    $before = Setting::query()->where('key', 'like', 'school.%')->orderBy('key')->pluck('value', 'key')->all();

    kontenAssertRejected(fn () => kontenUpdateProfile(['name' => 'Nama Baru', 'address' => 'Alamat baru', ...$overrides]), $reason, $field);

    expect(Setting::query()->where('key', 'like', 'school.%')->orderBy('key')->pluck('value', 'key')->all())->toBe($before);
})->with([
    'blank name' => [['name' => ''], 'name_required', 'name'],
    'name too long' => [['name' => str_repeat('a', 101)], 'too_long', 'name'],
    'tagline too long' => [['tagline' => str_repeat('a', 151)], 'too_long', 'tagline'],
]);

it('AC-34: leaves an invalid update from touching the logo', function () {
    kontenUpdateProfile(['logo' => kontenImage('logo.png', 300)]);
    $path = Setting::get('school.logo_path');

    kontenAssertRejected(fn () => kontenUpdateProfile(['name' => str_repeat('a', 101), 'logo' => kontenImage('baru.png', 300)]), 'too_long', 'name');

    expect(Setting::get('school.logo_path'))->toBe($path)->and(kontenFiles())->toBe([$path]);
});

it('AC-35: stores the logo on the public disk', function () {
    kontenUpdateProfile(['logo' => UploadedFile::fake()->image('logo.png', 200, 200)->size(300)]);

    $path = Setting::get('school.logo_path');

    expect($path)->toStartWith('school/')->toEndWith('.png')->and(Storage::disk('public')->exists($path))->toBeTrue()
        ->and(kontenFiles('private'))->toBe([]);
});

it('AC-35: replaces the logo and deletes the old file', function () {
    kontenUpdateProfile(['logo' => UploadedFile::fake()->image('a.png', 200, 200)->size(300)]);
    $old = Setting::get('school.logo_path');

    kontenUpdateProfile(['logo' => UploadedFile::fake()->image('b.png', 200, 200)->size(300)]);

    expect(Setting::get('school.logo_path'))->not->toBe($old)->and(Storage::disk('public')->exists($old))->toBeFalse()
        ->and(kontenFiles())->toHaveCount(1);
});

it('AC-35: keeps the logo when the form sends none and removes it on request', function () {
    kontenUpdateProfile(['logo' => UploadedFile::fake()->image('a.png', 200, 200)->size(300)]);
    $path = Setting::get('school.logo_path');

    kontenUpdateProfile(['name' => 'Nama baru']);

    expect(Setting::get('school.logo_path'))->toBe($path);

    kontenUpdateProfile(['remove_logo' => true]);

    expect(Setting::get('school.logo_path'))->toBeNull()->and(Storage::disk('public')->exists($path))->toBeFalse();
});

it('AC-35: rejects an svg or oversized logo and keeps the current one', function (Closure $logo) {
    kontenUpdateProfile(['logo' => UploadedFile::fake()->image('a.png', 200, 200)->size(300)]);
    $path = Setting::get('school.logo_path');

    kontenAssertRejected(fn () => kontenUpdateProfile(['logo' => $logo()]), 'image_invalid', 'logo');

    expect(Setting::get('school.logo_path'))->toBe($path)->and(kontenFiles())->toBe([$path]);
})->with([
    'svg' => fn () => fn () => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'),
    'webp' => fn () => fn () => UploadedFile::fake()->create('logo.webp', 10, 'image/webp'),
    'over 1 MB' => fn () => fn () => UploadedFile::fake()->image('logo.png', 200, 200)->size(1025),
]);

it('AC-35: accepts a logo of exactly 1024 KB', function () {
    kontenUpdateProfile(['logo' => UploadedFile::fake()->image('logo.png', 200, 200)->size(1024)]);

    expect(Setting::get('school.logo_path'))->not->toBeNull();
});

it('AC-36: logs one activity with only the changed keys', function () {
    kontenUpdateProfile();

    $activities = kontenActivities();

    expect($activities)->toHaveCount(1)
        ->and($activities[0]->causer_id)->toBe($this->admin->id)
        ->and($activities[0]->log_name)->toBe('konten');

    $changed = $activities[0]->properties['changed'];

    expect(array_keys($changed))->toContain('school.name')
        ->not->toContain('school.tagline', 'school.phone', 'school.address', 'school.email')
        ->and($changed['school.name']['new'])->toBe("Ma'had Al-Kitab");
});

it('AC-36: records old and new values and skips unchanged keys on the next save', function () {
    kontenUpdateProfile();
    kontenUpdateProfile(['tagline' => 'Motto baru']);

    $activities = kontenActivities();

    expect($activities)->toHaveCount(2)
        ->and(array_keys($activities[1]->properties['changed']))->toBe(['school.tagline'])
        ->and($activities[1]->properties['changed']['school.tagline'])->toBe(['old' => null, 'new' => 'Motto baru']);
});

it('AC-36: records nothing when nothing changed', function () {
    kontenUpdateProfile();
    kontenUpdateProfile();

    expect(kontenActivities())->toHaveCount(1);
});

it('AC-36: records nothing when validation fails', function () {
    kontenAssertRejected(fn () => kontenUpdateProfile(['tagline' => str_repeat('a', 151)]), 'too_long', 'tagline');

    expect(kontenActivities())->toHaveCount(0);
});

it('AC-37: reads the profile with fallbacks', function () {
    Setting::query()->where('key', 'like', 'school.%')->delete();
    array_map(Setting::forget(...), GetSchoolProfile::KEYS);
    Setting::set('school.name', 'Pondok Uji');

    $profile = app(GetSchoolProfile::class)->handle();

    expect($profile['name'])->toBe('Pondok Uji')
        ->and($profile)->not->toHaveKeys(['whatsapp', 'instagram_url', 'facebook_url', 'youtube_url'])
        ->and($profile['contacts'])->toBe([])
        ->and($profile['social_links'])->toBe([]);
});

it('AC-37: falls back to the app name and builds the logo url from the public disk', function () {
    Setting::set('school.name', null);

    expect(app(GetSchoolProfile::class)->handle()['name'])->toBe(config('app.name'));

    kontenUpdateProfile(['logo' => UploadedFile::fake()->image('logo.png', 200, 200)->size(300)]);
    $profile = app(GetSchoolProfile::class)->handle();

    expect($profile['logo_path'])->toBe(Setting::get('school.logo_path'))
        ->and($profile['logo_url'])->toBe(Storage::disk('public')->url($profile['logo_path']));
});

/**
 * Gambar PNG sungguhan yang dilaporkan berukuran `$kb` KB.
 */
function profilPng(string $name = 'a.png', int $kb = 100): UploadedFile
{
    return UploadedFile::fake()->image($name, 200, 200)->size($kb);
}

it('AC-12: saves the identity fields and logs only the changed keys', function () {
    kontenUpdateProfile([
        'short_name' => 'Al-Kitab', 'arabic_name' => 'معهد الكتاب', 'tagline' => 'Motto uji',
    ]);

    expect(Setting::get('school.short_name'))->toBe('Al-Kitab')
        ->and(Setting::get('school.arabic_name'))->toBe('معهد الكتاب')
        ->and(Setting::get('school.tagline'))->toBe('Motto uji')
        ->and(Setting::get('school.phone'))->toBeNull();

    $activities = kontenActivities()->where('description', 'school_profile_updated');

    expect($activities)->toHaveCount(1);

    $changed = array_keys($activities->first()->properties['changed']);

    expect($changed)->toContain('school.short_name', 'school.arabic_name')
        ->not->toContain('school.logo_path', 'school.logo_desktop_path', 'school.logo_mobile_path', 'school.favicon_path');
});

it('AC-13: does not log data that is sent again unchanged', function () {
    kontenUpdateProfile(['short_name' => 'Al-Kitab', 'arabic_name' => 'معهد الكتاب']);
    kontenUpdateProfile(['short_name' => 'Al-Kitab', 'arabic_name' => 'معهد الكتاب']);

    expect(kontenActivities()->where('description', 'school_profile_updated'))->toHaveCount(1);
});

it('AC-14: ignores the legacy contact keys of DemoData::SCHOOL', function () {
    Setting::set('school.contacts', [profilContact()]);
    Setting::set('school.social_links', [['platform' => 'instagram', 'label' => null, 'url' => 'https://instagram.com/x']]);
    $contacts = Setting::get('school.contacts');
    $socials = Setting::get('school.social_links');

    app(UpdateSchoolProfile::class)->handle(DemoData::SCHOOL, $this->admin);

    expect(Setting::get('school.name'))->toBe(DemoData::SCHOOL['name'])
        ->and(Setting::get('school.contacts'))->toBe($contacts)
        ->and(Setting::get('school.social_links'))->toBe($socials)
        ->and(Setting::query()->whereIn('key', ['school.whatsapp', 'school.instagram_url', 'school.facebook_url', 'school.youtube_url'])->exists())->toBeFalse();
});

it('AC-15: rejects a too long short name or arabic name and changes nothing', function (array $overrides, string $field) {
    kontenUpdateProfile(['short_name' => 'Lama']);
    $before = profilSettingsSnapshot();

    kontenAssertRejected(fn () => kontenUpdateProfile($overrides), 'too_long', $field);

    expect(profilSettingsSnapshot())->toBe($before);
})->with([
    'short name 31' => [['short_name' => 'ab'.str_repeat('a', 29)], 'short_name'],
    'arabic name 151' => [['arabic_name' => str_repeat('ب', 151)], 'arabic_name'],
]);

it('AC-15: accepts the maximum lengths', function () {
    kontenUpdateProfile(['short_name' => str_repeat('a', 30), 'arabic_name' => str_repeat('ب', 150)]);

    expect(Setting::get('school.short_name'))->toHaveLength(30);
});

it('AC-16: stores the desktop and mobile logos separately and leaves the print logo alone', function () {
    kontenUpdateProfile(['logo' => profilPng('cetak.png', 100)]);
    $print = Setting::get('school.logo_path');

    kontenUpdateProfile(['logo_desktop' => profilPng('desktop.png', 900), 'logo_mobile' => kontenImage('mobile.jpg', 300)]);

    $desktop = Setting::get('school.logo_desktop_path');
    $mobile = Setting::get('school.logo_mobile_path');

    expect($desktop)->toStartWith('school/')->toEndWith('.png')->not->toContain('desktop')
        ->and($mobile)->toStartWith('school/')->toEndWith('.jpg')->not->toContain('mobile')
        ->and(Setting::get('school.logo_path'))->toBe($print)
        ->and(kontenFiles())->toHaveCount(3)
        ->and(Storage::disk('public')->exists($desktop))->toBeTrue()
        ->and(Storage::disk('public')->exists($mobile))->toBeTrue();

    kontenUpdateProfile(['name' => 'Nama lain']);

    expect(Setting::get('school.logo_desktop_path'))->toBe($desktop)->and(Setting::get('school.logo_mobile_path'))->toBe($mobile);
});

it('AC-17: replaces and removes logo files without touching the copied print logo', function () {
    Storage::disk('public')->put('school/cetak.png', 'cetak');
    Storage::disk('public')->put('school/salinan.png', 'cetak');
    Setting::set('school.logo_path', 'school/cetak.png');
    Setting::set('school.logo_desktop_path', 'school/salinan.png');

    kontenUpdateProfile(['logo_desktop' => profilPng('baru.png', 100)]);
    $new = Setting::get('school.logo_desktop_path');

    expect($new)->not->toBe('school/salinan.png')
        ->and(Storage::disk('public')->exists('school/salinan.png'))->toBeFalse()
        ->and(Storage::disk('public')->exists($new))->toBeTrue()
        ->and(Storage::disk('public')->exists('school/cetak.png'))->toBeTrue()
        ->and(Setting::get('school.logo_path'))->toBe('school/cetak.png');

    kontenUpdateProfile(['remove_logo_desktop' => true]);

    expect(Setting::get('school.logo_desktop_path'))->toBeNull()
        ->and(Storage::disk('public')->exists($new))->toBeFalse()
        ->and(Storage::disk('public')->exists('school/cetak.png'))->toBeTrue();
});

it('AC-17: removes the mobile logo and the favicon on request', function () {
    kontenUpdateProfile(['logo_mobile' => profilPng('m.png', 100), 'favicon' => profilPng('f.png', 100)]);
    $files = kontenFiles();

    expect($files)->toHaveCount(2);

    kontenUpdateProfile(['remove_logo_mobile' => true, 'remove_favicon' => true]);

    expect(Setting::get('school.logo_mobile_path'))->toBeNull()->and(Setting::get('school.favicon_path'))->toBeNull()
        ->and(kontenFiles())->toBe([]);
});

it('AC-18: rejects a favicon that is not a png within 256 KB', function (Closure $favicon) {
    kontenUpdateProfile(['name' => 'Nama Lama']);
    $before = profilSettingsSnapshot();

    kontenAssertRejected(fn () => kontenUpdateProfile(['name' => 'Nama Baru', 'favicon' => $favicon()]), 'favicon_invalid', 'favicon');

    expect(profilSettingsSnapshot())->toBe($before)->and(kontenFiles())->toBe([]);
})->with([
    'jpg' => fn () => fn () => kontenImage('f.jpg', 50),
    'png 300 KB' => fn () => fn () => profilPng('f.png', 300),
    'png 257 KB' => fn () => fn () => profilPng('f.png', 257),
]);

it('AC-18: accepts a png favicon of 197 KB and of exactly 256 KB', function (int $kb) {
    kontenUpdateProfile(['favicon' => profilPng('f.png', $kb)]);

    expect(Setting::get('school.favicon_path'))->toStartWith('school/')->toEndWith('.png');
})->with([197, 256]);

it('AC-18: rejects gif and svg logos on every logo field', function (string $field, string $mime, string $extension) {
    kontenAssertRejected(
        fn () => kontenUpdateProfile([$field => UploadedFile::fake()->create("l.{$extension}", 10, $mime)]),
        'image_invalid',
        $field,
    );

    expect(kontenFiles())->toBe([]);
})->with([
    'logo gif' => ['logo', 'image/gif', 'gif'],
    'desktop svg' => ['logo_desktop', 'image/svg+xml', 'svg'],
    'mobile gif' => ['logo_mobile', 'image/gif', 'gif'],
]);

it('AC-19: leaves no file and no change when another field is invalid', function () {
    kontenUpdateProfile(['name' => 'Nama Lama']);
    $before = profilSettingsSnapshot();
    $activities = kontenActivities()->count();

    kontenAssertRejected(
        fn () => kontenUpdateProfile(['name' => 'Nama Baru', 'tagline' => str_repeat('a', 151), 'logo_desktop' => profilPng('d.png', 100), 'favicon' => profilPng('f.png', 100)]),
        'too_long',
        'tagline',
    );

    expect(kontenFiles())->toBe([])->and(profilSettingsSnapshot())->toBe($before)->and(kontenActivities()->count())->toBe($activities);
});
