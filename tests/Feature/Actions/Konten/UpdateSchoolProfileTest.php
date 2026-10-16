<?php

use App\Actions\Konten\GetSchoolProfile;
use App\Actions\Konten\UpdateSchoolProfile;
use App\Models\Setting;
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
        'name' => "Ma'had Al-Khair",
        'tagline' => '',
        'address' => 'Jl. Pesantren No. 1',
        'phone' => '0812-3456-7890',
        'whatsapp' => '+62 812 3456 7890',
        'email' => 'info@alkhair.sch.id',
        'instagram_url' => 'https://instagram.com/alkhair',
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

it('AC-33: writes the school profile keys with normalized phone numbers', function () {
    kontenUpdateProfile();

    expect(Setting::get('school.name'))->toBe("Ma'had Al-Khair")
        ->and(Setting::get('school.phone'))->toBe('081234567890')
        ->and(Setting::get('school.whatsapp'))->toBe('+6281234567890')
        ->and(Setting::get('school.email'))->toBe('info@alkhair.sch.id')
        ->and(Setting::get('school.address'))->toBe('Jl. Pesantren No. 1')
        ->and(Setting::get('school.instagram_url'))->toBe('https://instagram.com/alkhair')
        ->and(Setting::get('school.tagline'))->toBeNull()
        ->and(Setting::get('school.facebook_url'))->toBeNull()
        ->and(Setting::get('school.youtube_url'))->toBeNull();
});

it('AC-33: normalizes separators in phone numbers', function (string $input, string $expected) {
    kontenUpdateProfile(['phone' => $input]);

    expect(Setting::get('school.phone'))->toBe($expected);
})->with([
    'dots' => ['0812.3456.7890', '081234567890'],
    'parentheses' => ['(0274) 123456', '0274123456'],
    'plus' => ['+62 274 123456', '+62274123456'],
]);

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
    'bad email' => [['email' => 'bukan-email'], 'email_invalid', 'email'],
    'javascript url' => [['instagram_url' => 'javascript:alert(1)'], 'url_invalid', 'instagram_url'],
    'ftp url' => [['instagram_url' => 'ftp://x.test'], 'url_invalid', 'instagram_url'],
    'bad facebook url' => [['facebook_url' => 'facebook'], 'url_invalid', 'facebook_url'],
    'bad youtube url' => [['youtube_url' => 'data:text/html,x'], 'url_invalid', 'youtube_url'],
    'letters in phone' => [['phone' => 'abc'], 'phone_invalid', 'phone'],
    'short phone' => [['phone' => '123'], 'phone_invalid', 'phone'],
    'bad whatsapp' => [['whatsapp' => '62-abc'], 'phone_invalid', 'whatsapp'],
    'address too long' => [['address' => str_repeat('a', 501)], 'too_long', 'address'],
]);

it('AC-34: leaves an invalid update from touching the logo', function () {
    kontenUpdateProfile(['logo' => kontenImage('logo.png', 300)]);
    $path = Setting::get('school.logo_path');

    kontenAssertRejected(fn () => kontenUpdateProfile(['email' => 'salah', 'logo' => kontenImage('baru.png', 300)]), 'email_invalid', 'email');

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

    expect(array_keys($changed))->toContain('school.phone', 'school.address', 'school.email')
        ->not->toContain('school.tagline', 'school.facebook_url')
        ->and($changed['school.phone']['new'])->toBe('081234567890');
});

it('AC-36: records old and new values and skips unchanged keys on the next save', function () {
    kontenUpdateProfile();
    kontenUpdateProfile(['address' => 'Alamat baru']);

    $activities = kontenActivities();

    expect($activities)->toHaveCount(2)
        ->and(array_keys($activities[1]->properties['changed']))->toBe(['school.address'])
        ->and($activities[1]->properties['changed']['school.address'])->toBe(['old' => 'Jl. Pesantren No. 1', 'new' => 'Alamat baru']);
});

it('AC-36: records nothing when nothing changed', function () {
    kontenUpdateProfile();
    kontenUpdateProfile();

    expect(kontenActivities())->toHaveCount(1);
});

it('AC-36: records nothing when validation fails', function () {
    kontenAssertRejected(fn () => kontenUpdateProfile(['email' => 'salah']), 'email_invalid', 'email');

    expect(kontenActivities())->toHaveCount(0);
});

it('AC-37: reads the profile with fallbacks', function () {
    Setting::query()->where('key', 'like', 'school.%')->delete();
    array_map(Setting::forget(...), GetSchoolProfile::KEYS);
    Setting::set('school.name', 'Pondok Uji');

    $profile = app(GetSchoolProfile::class)->handle();

    expect($profile['name'])->toBe('Pondok Uji')
        ->and(array_keys($profile))->toBe(['name', 'tagline', 'address', 'phone', 'whatsapp', 'email', 'instagram_url', 'facebook_url', 'youtube_url', 'logo_path', 'logo_url'])
        ->and(collect($profile)->except('name')->filter(fn (mixed $value): bool => $value !== null)->all())->toBe([]);
});

it('AC-37: falls back to the app name and builds the logo url from the public disk', function () {
    Setting::set('school.name', null);

    expect(app(GetSchoolProfile::class)->handle()['name'])->toBe(config('app.name'));

    kontenUpdateProfile(['logo' => UploadedFile::fake()->image('logo.png', 200, 200)->size(300)]);
    $profile = app(GetSchoolProfile::class)->handle();

    expect($profile['logo_path'])->toBe(Setting::get('school.logo_path'))
        ->and($profile['logo_url'])->toBe(Storage::disk('public')->url($profile['logo_path']));
});
