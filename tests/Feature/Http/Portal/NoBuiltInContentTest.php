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

    // F037: profil draf dan kontak tanpa data kontak menjadi 404 di instalasi baru.
    $this->get('/profil')->assertNotFound();
    $this->get('/kontak')->assertNotFound();

    foreach (['/'] as $url) {
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

it('AC-14: a fresh install home has no blocks and shows no content sections', function () {
    profilFreshInstall();
    Setting::set('modules.konten.enabled', true);

    $data = app(GetPortalHomeData::class)->handle();
    $html = $this->get('/')->assertOk()->getContent();

    expect($data['blocks'])->toBe([])->and($data['hasHero'])->toBeFalse()
        ->and(Setting::get('portal.home_blocks'))->toBeNull()
        ->and($html)->not->toContain('<h2')->not->toContain('<iframe')->not->toContain('role="region"');
});

it('AC-7: renders a features block without a section heading when the heading is empty', function () {
    portalBoot();
    $baseline = substr_count($this->get('/')->getContent(), '<h2');
    blkSet([blk('features', ['heading' => '', 'items' => [['title' => 'Kartu Satu', 'text' => 'Isi satu'], ['title' => 'Kartu Dua', 'text' => null], ['title' => 'Kartu Tiga', 'text' => null]]])]);

    $html = $this->get('/')->assertOk()->getContent();

    expect(profilVisibleText($html))->toContain('Kartu Satu')->toContain('Kartu Dua')->toContain('Kartu Tiga')
        ->and(substr_count($html, '<h2'))->toBe($baseline)
        ->and($html)->not->toMatch('/<h2\b[^>]*>\s*Kartu/');
});

it('AC-1: an empty or draft profile page never creates a home section by itself', function () {
    portalBoot();
    StaticPage::findBySlug('profil')->update(['title' => 'Tentang Yayasan', 'body' => '<p>Isi profil yayasan.</p>']);

    $html = $this->get('/')->assertOk()->getContent();

    expect(profilVisibleText($html))->not->toContain('Isi profil yayasan.')
        ->and($html)->not->toMatch('/<h2\b[^>]*>\s*Tentang Yayasan\s*<\/h2>/');
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

it('AC-39: a fresh install has only the three system pages and interface labels', function () {
    profilFreshInstall();

    expect(StaticPage::query()->count())->toBe(3)
        ->and(StaticPage::query()->pluck('slug')->sort()->values()->all())->toBe(['kontak', 'profil', 'visi-misi'])
        ->and(Setting::get('portal.navigation_primary'))->toBeNull()
        ->and(Setting::get('portal.footer_text'))->toBeNull();

    $labels = portalNavTexts($this->get('/')->assertOk()->getContent());
    $interface = [__('pondok.portal.nav.home'), __('pondok.portal.nav.news'), __('pondok.portal.nav.gallery'), __('pondok.portal.nav.videos'), __('pondok.portal.nav.psb_info'), __('pondok.portal.nav.login'), __('pondok.portal.nav.register')];

    expect(array_values(array_diff($labels, $interface)))->toBe([]);
});

it('AC-14: a fresh install has no footer text and no quote in the footer, even with an archived quote', function () {
    profilFreshInstall();
    Setting::set('portal.archived_footer_quote', ['arabic' => 'نص عربي للاختبار', 'translation' => 'Terjemah arsip', 'source' => 'Sumber arsip']);

    $footer = profilFooter($this->get('/')->assertOk()->getContent());

    expect(Setting::get('portal.footer_text'))->toBeNull()
        ->and($footer)->not->toContain('<blockquote')->not->toContain('lang="ar"')->not->toContain('Terjemah arsip')
        ->and($this->get('/')->getContent())->not->toContain('Terjemah arsip');
});
