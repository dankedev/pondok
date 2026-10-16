<?php

use App\Actions\Konten\GetPortalHomeData;
use App\Models\Setting;
use App\Models\StaticPage;
use Database\Seeders\InstallSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Arr;

/**
 * Kata yang tidak boleh berasal dari aplikasi (huruf kecil; "ali 'imran" memakai apostrof lurus).
 *
 * @var list<string>
 */
const BUILT_IN_WORDS = ['bismillah', 'بسم', 'assalamu', 'ahlan', 'selamat datang', 'mengapa', 'mengenal', 'situs resmi', 'qs.', "ali 'imran", 'sepanjang tahun', 'lorem', 'ipsum'];

it('AC-54: renders no built in content text on a fresh install', function () {
    profilFreshInstall();
    Setting::set('modules.konten.enabled', true);
    Setting::set('modules.psb.enabled', true);

    foreach (['/', '/profil', '/kontak'] as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        $decoded = mb_strtolower(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $visible = mb_strtolower(profilVisibleText($html));

        $found = array_values(array_filter(BUILT_IN_WORDS, fn (string $word): bool => str_contains($decoded, $word)));

        expect($found)->toBe([], "{$url} memuat teks bawaan")
            ->and($visible)->not->toContain('placeholder')
            ->and($html)->not->toContain('lang="ar"')->not->toContain('<blockquote')
            ->not->toContain('snap-x')->not->toContain('role="region"')->not->toContain('<iframe');
    }
});

it('AC-54: has no meta description on the fresh home page and no profile keys in settings', function () {
    profilFreshInstall();

    expect($this->get('/')->assertOk()->getContent())->not->toContain('<meta name="description"')
        ->and(Setting::query()->where('key', 'like', 'portal.%')->whereNotNull('value')->count())->toBe(0);

    foreach (['school.arabic_name', 'school.contacts', 'school.social_links'] as $key) {
        expect(Setting::get($key))->toBeNull();
    }
});

it('AC-55: carries no content text in the translations and the install seeders', function () {
    $strings = Arr::dot(require lang_path('id/pondok.php'));

    $violations = [];

    foreach ($strings as $key => $value) {
        if (! is_string($value)) {
            continue;
        }

        if (preg_match('/\p{Arabic}/u', $value) === 1) {
            $violations[] = "pondok.{$key}: huruf Arab";
        }

        foreach (['bismillah', 'assalamu', 'ahlan', 'selamat datang', 'mengapa memilih', 'mengenal kami', 'situs resmi', 'qs.', "ali 'imran", 'sepanjang tahun'] as $word) {
            if (str_contains(mb_strtolower($value), $word)) {
                $violations[] = "pondok.{$key}: {$word}";
            }
        }
    }

    expect($violations)->toBe([]);

    expect(Arr::has(require lang_path('id/pondok.php'), 'portal.meta.default_description'))->toBeFalse()
        ->and(Arr::has(require lang_path('id/pondok.php'), 'portal.home.profile_title'))->toBeFalse()
        ->and(__('pondok.portal.contact.title'))->toBe('Informasi kontak');

    $this->seed(SettingSeeder::class);
    $this->seed(InstallSeeder::class);

    expect(Setting::query()->where('key', 'like', 'school.%')->pluck('key')->all())->toBe(['school.name'])
        ->and(Setting::query()->where('key', 'like', 'portal.%')->count())->toBe(0);
});

it('AC-56: renders highlights without a section heading when the heading is empty', function () {
    portalBoot();
    $baseline = substr_count($this->get('/')->getContent(), '<h2');
    Setting::set('portal.highlights', [['title' => 'Kartu Satu', 'description' => 'Isi satu'], ['title' => 'Kartu Dua', 'description' => null], ['title' => 'Kartu Tiga', 'description' => null]]);

    $html = $this->get('/')->assertOk()->getContent();

    expect(profilVisibleText($html))->toContain('Kartu Satu')->toContain('Kartu Dua')->toContain('Kartu Tiga')
        ->and(substr_count($html, '<h2'))->toBe($baseline)
        ->and($html)->not->toMatch('/<h2\b[^>]*>\s*Kartu/');
});

it('AC-56: takes the highlights heading from settings', function () {
    portalBoot();
    Setting::set('portal.highlights', [['title' => 'Kartu Satu', 'description' => null]]);
    Setting::set('portal.highlights_heading', 'Mengapa memilih kami');

    expect($this->get('/')->assertOk()->getContent())->toMatch('/<h2\b[^>]*>\s*Mengapa memilih kami\s*<\/h2>/');
});

it('AC-57: uses the title of the profile page for the home section', function () {
    portalBoot();
    StaticPage::findBySlug('profil')->update(['title' => 'Tentang Yayasan', 'body' => '<p>Isi profil yayasan.</p>']);

    $data = app(GetPortalHomeData::class)->handle();
    $html = $this->get('/')->assertOk()->getContent();

    expect($data['profileTitle'])->toBe('Tentang Yayasan')
        ->and($html)->toMatch('/<h2\b[^>]*>\s*Tentang Yayasan\s*<\/h2>/')
        ->and(profilVisibleText($html))->toContain('Isi profil yayasan.')->not->toContain('Mengenal kami');
});

it('AC-57: has no profile title or summary and no section for an empty profile page', function () {
    portalBoot();
    StaticPage::findBySlug('profil')->update(['title' => 'Tentang Yayasan', 'body' => '']);

    $data = app(GetPortalHomeData::class)->handle();

    expect($data['profileTitle'])->toBeNull()->and($data['profileSummary'])->toBeNull()
        ->and($this->get('/')->assertOk()->getContent())->not->toContain('seksi-profil')->not->toContain('Tentang Yayasan');
});

it('AC-57: returns null media and profile title when the content module is off', function () {
    portalBoot();
    $album = portalAlbumWithPhotos(2, ['title' => 'Fasilitas']);
    $video = kontenVideo();
    StaticPage::findBySlug('profil')->update(['body' => '<p>Isi</p>']);
    profilSet(['portal.hero_album_id' => $album->id, 'portal.profile_video_id' => $video->id, 'portal.psb_video_id' => $video->id, 'modules.psb.enabled' => true]);
    Setting::set('modules.konten.enabled', false);

    $data = app(GetPortalHomeData::class)->handle();

    expect($data['profileTitle'])->toBeNull()->and($data['heroAlbum'])->toBeNull()
        ->and($data['profileVideo'])->toBeNull()->and($data['psbVideo'])->toBeNull();
});

it('AC-57: builds the hero album with at most ten photos in order and tolerates missing references', function () {
    portalBoot();
    $album = kontenAlbum(['title' => 'Fasilitas']);

    foreach (range(12, 1) as $order) {
        kontenPhoto($album, ['sort_order' => $order, 'caption' => "Foto {$order}"]);
    }

    $video = kontenVideo(['title' => 'Video Uji']);
    profilSet(['portal.hero_album_id' => $album->id, 'portal.profile_video_id' => $video->id, 'portal.psb_video_id' => 9999, 'modules.psb.enabled' => true]);

    $data = app(GetPortalHomeData::class)->handle();

    expect($data['heroAlbum']['title'])->toBe('Fasilitas')
        ->and(data_get($data['heroAlbum']['photos'], '*.sort_order'))->toBe(range(1, 10))
        ->and(data_get($data['profileVideo'], 'title'))->toBe('Video Uji')
        ->and($data['psbVideo'])->toBeNull();

    Setting::set('modules.psb.enabled', false);
    Setting::set('portal.psb_video_id', $video->id);

    expect(app(GetPortalHomeData::class)->handle()['psbVideo'])->toBeNull();
});

it('AC-58: takes the meta description from the tagline, then the footer description, then none', function () {
    portalBoot();
    Setting::set('school.tagline', 'Tagline uji');
    Setting::set('portal.footer_description', 'Deskripsi footer uji');

    expect(profilTag($this->get('/')->getContent(), 'meta', ['name="description"', 'content="Tagline uji"']))->not->toBeNull();

    Setting::set('school.tagline', '');

    expect(profilTag($this->get('/')->getContent(), 'meta', ['name="description"', 'content="Deskripsi footer uji"']))->not->toBeNull();

    Setting::set('portal.footer_description', null);

    expect($this->get('/')->getContent())->not->toContain('<meta name="description"');
});
