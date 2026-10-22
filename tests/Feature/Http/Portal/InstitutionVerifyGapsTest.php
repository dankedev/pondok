<?php

use App\Actions\Konten\UpdatePortalNavigation;
use App\Actions\Konten\UpdateSchoolContacts;
use App\Actions\Konten\UpdateSchoolProfile;
use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * VERIFY F036: celah di luar test RED.
 */
beforeEach(function () {
    portalBoot();
    kontenBoot();
    Setting::set('school.name', "Ma'had Al-Kitab");
});

it('VERIFY: escapes every free text field in header, home and footer', function () {
    $x = '<script>alert(7)</script>';
    profilSet([
        'school.short_name' => $x, 'school.arabic_name' => $x, 'school.tagline' => $x, 'school.address' => $x,
        'school.contacts' => [profilContact(['label' => 'L', 'name' => $x, 'phone' => $x])],
        'school.social_links' => [['platform' => 'lainnya', 'label' => $x, 'url' => 'https://a.test/"onmouseover="x']],
    ]);

    blkSet([blk('hero', ['eyebrow' => $x, 'title' => $x, 'text' => $x]), blk('cta', ['heading' => $x, 'text' => $x, 'variant' => 'light'])]);
    ftrSet([ftr('text', ['heading' => $x, 'body' => $x, 'show_logo' => true]), ftr('contacts', ['heading' => $x]), ftr('social', ['heading' => $x])]);

    foreach (['/', '/kontak'] as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        expect($html)->not->toContain('<script>alert(7)')->not->toContain('"onmouseover="x');
    }
});

it('VERIFY: tolerates junk contact phones stored by hand without a 500', function () {
    profilSet(['school.contacts' => [
        profilContact(['phone' => 'abc', 'is_whatsapp' => true]),
        profilContact(['phone' => '+62 812', 'is_whatsapp' => false]),
    ]]);

    $this->get('/')->assertOk();
    $this->get('/kontak')->assertOk();
});

it('VERIFY: drops hand stored links with quotes, backslash host tricks and uppercase schemes only when unsafe', function () {
    profilSet(['school.social_links' => [
        ['platform' => 'instagram', 'label' => null, 'url' => 'JAVASCRIPT:alert(1)'],
        ['platform' => 'instagram', 'label' => null, 'url' => 'https://a.test\\@evil.test'],
        ['platform' => 'instagram', 'label' => null, 'url' => 'https:///x'],
        ['platform' => 'instagram', 'label' => null, 'url' => "https://a.test/x\u{0085}y"],
        ['platform' => 'instagram', 'label' => null, 'url' => 'data:text/html,x'],
        ['platform' => 'facebook', 'label' => null, 'url' => 'https://facebook.com/ok'],
    ]]);
    ftrSet([ftr('social')]);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('https://facebook.com/ok')->not->toContain('JAVASCRIPT:')->not->toContain('evil.test')
        ->not->toContain('data:text/html')->not->toContain('https:///x');
});

it('VERIFY: rejects tricky social, footer widget and button urls on write', function (string $url) {
    $admin = kontenUser('admin-sekolah');
    Setting::set('school.social_links', null);

    expect(fn () => app(UpdateSchoolContacts::class)->handle(['social_links' => [['platform' => 'website', 'url' => $url]]], $admin))
        ->toThrow(ValidationException::class);
    expect(fn () => app(UpdatePortalNavigation::class)->handle(['primary' => [], 'header_button' => navItem('url', 'x', ['url' => $url])], $admin))
        ->toThrow(ValidationException::class);
    expect(fn () => ftrSave([ftr('links', ['items' => [navItem('url', 'x', ['url' => $url])]])]))->toThrow(ValidationException::class);
    expect(Setting::get('school.social_links'))->toBeNull();
})->with(['https://a.test\\@evil.test', 'https:///x', 'JAVASCRIPT:alert(1)', "https://a.test/\u{0085}", 'vbscript:x', 'https://']);

it('VERIFY: rejects fake mime uploads on logo and favicon and stores nothing', function (string $field) {
    $admin = kontenUser('admin-sekolah');
    Storage::fake('public');
    $tmp = tempnam(sys_get_temp_dir(), 'fake');
    file_put_contents($tmp, '<?php echo 1; ?> bukan gambar');
    $fake = new UploadedFile($tmp, 'logo.png', 'image/png', null, true);

    expect(fn () => app(UpdateSchoolProfile::class)->handle(['name' => 'Nama', $field => $fake], $admin))
        ->toThrow(ValidationException::class);
    expect(Storage::disk('public')->allFiles())->toBe([]);
})->with(['logo', 'logo_desktop', 'logo_mobile', 'favicon']);

it('VERIFY: replacing the desktop logo keeps the other logo files', function () {
    $admin = kontenUser('admin-sekolah');
    Storage::fake('public');
    $png = fn (string $name) => UploadedFile::fake()->image($name, 50, 50);
    app(UpdateSchoolProfile::class)->handle(['name' => 'N', 'logo' => $png('a.png'), 'logo_desktop' => $png('b.png'), 'logo_mobile' => $png('c.png'), 'favicon' => $png('d.png')], $admin);
    $keep = [Setting::get('school.logo_path'), Setting::get('school.logo_mobile_path'), Setting::get('school.favicon_path')];

    app(UpdateSchoolProfile::class)->handle(['name' => 'N', 'logo_desktop' => $png('e.png'), 'remove_logo_mobile' => true], $admin);

    expect(Storage::disk('public')->exists($keep[0]))->toBeTrue()->and(Storage::disk('public')->exists($keep[2]))->toBeTrue()
        ->and(Setting::get('school.logo_mobile_path'))->toBeNull()->and(Storage::disk('public')->exists($keep[1]))->toBeFalse();
    expect(Setting::get('school.logo_path'))->toBe($keep[0])->and(Storage::disk('public')->exists($keep[0]))->toBeTrue();
});

it('VERIFY: a fresh install with only some fields filled shows no built in content', function () {
    profilFreshInstall();
    Setting::set('school.tagline', 'Slogan uji');
    ftrSet([ftr('links', ['items' => [navItem('url', 'Tautan Uji', ['url' => '/berita'])]])]);

    foreach (['/', '/profil', '/kontak', '/visi-misi'] as $url) {
        $html = html_entity_decode(profilVisibleText($this->get($url)->getContent()));
        foreach (['bismillah', 'assalamu', 'ahlan', 'selamat datang', 'mengapa', 'mengenal', 'situs resmi', "ali 'imran", 'sepanjang tahun', 'lorem'] as $word) {
            expect(mb_strtolower($html))->not->toContain($word, "{$url} memuat {$word}");
        }
    }
});

it('VERIFY: the portal home runs each settings query at most once', function () {
    profilSet(['school.contacts' => [profilContact()], 'school.map_url' => 'https://maps.app.goo.gl/x']);
    blkSet([blk('hero', ['title' => 'A']), blk('features', ['items' => [['title' => 'A']]]), blk('feed', ['source' => 'posts'])]);
    DB::enableQueryLog();

    $this->get('/')->assertOk();

    $queries = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], '"settings"'));
    $dupes = $queries->groupBy(fn (array $q): string => $q['query'].json_encode($q['bindings']))->filter(fn ($g): bool => $g->count() > 1);
    expect($dupes->keys()->all())->toBe([], 'query settings berulang');
});

it('VERIFY: demo data carries no real brochure number', function () {
    $text = collect(glob(database_path('seeders/Demo/*.php')))->map(fn (string $f): string => file_get_contents($f))->implode("\n");

    expect($text)->not->toContain('085832333725')->not->toContain('085669941929')->not->toContain('6285832333725');
});

it('VERIFY: migration down then up keeps the whatsapp number without duplicates', function () {
    profilRawSet('school.whatsapp', '0812-0000-0170');
    profilMigration()->up();
    profilMigration()->down();
    expect(profilRawGet('school.whatsapp'))->toBe('081200000170');
    profilMigration()->up();

    expect(profilRawGet('school.contacts'))->toHaveCount(1)->and(profilRawHas('school.whatsapp'))->toBeFalse();
});
