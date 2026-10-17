<?php

use App\Actions\Psb\GenerateRegistrationInvoices;
use App\Livewire\Psb\ApplicationStatus;
use App\Livewire\Psb\RegistrationForm;
use App\Models\Album;
use App\Models\Post;
use App\Models\Setting;
use App\Models\StaticPage;
use App\Models\Video;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * F035: identitas visual portal (navy dan emas dari logo Al-Khair, gaya modern ringan).
 * Hampir semua perubahan bersifat visual, jadi test hanya mengunci perilaku bermakna:
 * token logo, tanpa aset eksternal, struktur nama Arab, hiasan aman, section kosong tetap tersembunyi,
 * dan Filament tetap emerald. Kelas warna murni tidak dikunci.
 */

/**
 * Berkas Blade portal/PSB yang dijaga (tanpa `livewire/psb` bila `$withLivewire` false).
 *
 * @return list<string>
 */
function identitasViews(bool $withLivewire = true): array
{
    $dirs = [resource_path('views/portal'), resource_path('views/components/ui'), resource_path('views/components/portal')];

    if ($withLivewire) {
        $dirs[] = resource_path('views/livewire/psb');
    }

    $files = [resource_path('views/layouts/public.blade.php'), resource_path('views/errors/404.blade.php')];

    foreach ($dirs as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }
    }

    return array_values(array_filter($files, 'is_file'));
}

/**
 * Simpan logo palsu di disk `public` dan isi key settings-nya.
 *
 * @param  array<string, string>  $files  key settings => path berkas
 * @return array<string, string> key settings => URL
 */
function identitasLogos(array $files): array
{
    $urls = [];

    foreach ($files as $key => $path) {
        Storage::disk('public')->put($path, "isi-{$path}");
        Setting::set($key, $path);
        $urls[$key] = Storage::disk('public')->url($path);
    }

    return $urls;
}

/**
 * Elemen hiasan (`portal-decor-`) sebagai pasangan [tag pembuka, isi].
 *
 * @return list<array{0: string, 1: string}>
 */
function identitasDecorations(string $html): array
{
    preg_match_all('#(<[a-z0-9]+\b[^>]*class="[^"]*portal-decor-[^"]*"[^>]*>)(.*?)</[a-z0-9]+>#is', $html, $matches, PREG_SET_ORDER);

    return array_map(fn (array $match): array => [$match[1], $match[2]], $matches);
}

/**
 * Bagian slider (dari kontainer `role="region"` sampai akhir seksinya), sama dengan helper F036.
 */
function identitasSlider(string $html): string
{
    return preg_match('#<div\b[^>]*role="region"[^>]*>.*?</section>#is', $html, $match) === 1 ? $match[0] : '';
}

/**
 * Tag `input` dan `select` terlihat yang wajib bergaya pill (honeypot, radio, checkbox, hidden dikecualikan).
 *
 * @return list<string>
 */
function identitasVisibleFields(string $html): array
{
    preg_match_all('/<(?:input|select)\b[^>]*>/i', $html, $tags);

    return array_values(array_filter($tags[0], fn (string $tag): bool => ! str_contains($tag, 'tabindex="-1"')
        && ! preg_match('/\btype="(?:radio|checkbox|hidden)"/', $tag)));
}

/**
 * Beranda dengan semua section terisi (urutan penanda dicek di test urutan).
 */
function identitasFullHome(): object
{
    portalPsbOpen();
    $slider = portalAlbumWithPhotos(3, ['title' => 'Fasilitas']);
    $gallery = portalAlbumWithPhotos(2, ['title' => 'Album Galeri']);
    $profileVideo = kontenVideo(['title' => 'Video Profil Uji', 'youtube_id' => 'BBBBBBBBBBB']);
    kontenVideo(['title' => 'Video Unggulan Uji', 'youtube_id' => 'AAAAAAAAAAA', 'is_featured' => true]);
    portalPost(['title' => 'Berita Uji']);
    portalPost(['title' => 'Prestasi Uji', 'type' => Post::TYPE_ACHIEVEMENT]);
    profilSet(['school.arabic_name' => 'معهد الخير', 'school.contacts' => [profilContact()]]);
    blkSet([
        blk('hero', ['eyebrow' => 'Sambutan uji', 'title' => 'Judul hero uji', 'text' => 'Subjudul hero uji', 'album_id' => $slider->id]),
        blk('features', ['heading' => 'Judul keunggulan uji', 'items' => [['title' => 'Kartu Satu', 'text' => 'Deskripsi satu']]]),
        blk('video', ['video_id' => $profileVideo->id]),
        blk('text_image', ['heading' => 'Profil Pondok', 'text' => '<p>Profil kami.</p>']),
        blk('feed', ['source' => 'posts']),
        blk('feed', ['source' => 'achievements']),
        blk('feed', ['source' => 'videos']),
        blk('feed', ['source' => 'albums']),
        blk('quote', ['text' => 'نص عربي للاختبار', 'rtl' => true]),
    ]);

    return (object) ['slider' => $slider, 'gallery' => $gallery];
}

describe('token dan font di sumber', function () {
    it('AC-1: defines navy and gold tokens from the logo without emerald', function () {
        $css = file_get_contents(resource_path('css/app.css'));

        expect($css)->toMatch('/--color-primary-950:\s*#000a3c\s*;/i')
            ->and($css)->toMatch('/--color-accent-500:\s*#f8b800\s*;/i')
            ->and(mb_strtolower($css))->not->toContain('emerald');
    });

    it('AC-8: defines shadow tokens in the theme', function () {
        $css = file_get_contents(resource_path('css/app.css'));

        expect($css)->toMatch('/--shadow-card:\s*[^;]+;/')
            ->and($css)->toMatch('/--shadow-card-lg:\s*[^;]+;/')
            ->and($css)->toMatch('/--shadow-button:\s*[^;]+;/');
    });

    it('AC-13: has no arbitrary values in portal views', function () {
        foreach (identitasViews(withLivewire: false) as $file) {
            $name = str_replace(resource_path('views/'), '', $file);

            expect(preg_match_all('/[a-z0-9:-]+-\[[^\]\s]+\]/i', file_get_contents($file), $found))
                ->toBe(0, "nilai arbitrer di {$name}: ".implode(', ', $found[0]));
        }
    });

    it('AC-16: self hosts plus jakarta sans and noto naskh arabic', function () {
        $vite = file_get_contents(base_path('vite.config.js'));
        $css = file_get_contents(resource_path('css/app.css'));

        expect($vite)->toMatch("/bunny\(\s*'Plus Jakarta Sans'\s*,\s*\{[^}]*weights:\s*\[[^\]]*700/s")
            ->and($vite)->toMatch("/bunny\(\s*'Noto Naskh Arabic'\s*,\s*\{[^}]*subsets:\s*\[[^\]]*'arabic'[^}]*preload:\s*false/s")
            ->and($vite)->not->toContain('Instrument Sans')
            ->and($css)->toMatch("/--font-sans:\s*'Plus Jakarta Sans'/")
            ->and($css)->toMatch("/--font-arabic:\s*'Noto Naskh Arabic'/");
    });

    it('AC-10: bundles fonts at build time only', function () {
        $vite = file_get_contents(base_path('vite.config.js'));
        $css = file_get_contents(resource_path('css/app.css'));

        expect($vite)->toContain('bunny(')->not->toContain('https://')->not->toContain('http://')
            ->and($css)->not->toContain('@import url(')->not->toContain('url(http');
    });
});

describe('bentuk pill', function () {
    it('AC-7: renders pill buttons', function () {
        foreach (['primary', 'secondary', 'danger'] as $variant) {
            $link = Blade::render('<x-ui.button variant="'.$variant.'" href="/x">Tes</x-ui.button>');
            $button = Blade::render('<x-ui.button variant="'.$variant.'">Tes</x-ui.button>');

            expect($link)->toMatch('/<a\b[^>]*'.profilClassPattern(['rounded-full']).'/', "tautan {$variant}")
                ->and($button)->toMatch('/<button\b[^>]*'.profilClassPattern(['rounded-full']).'/', "tombol {$variant}");
        }

        $chips = Blade::render('<x-portal.filter-chips label="Jenis" :items="$items" />', ['items' => [
            ['label' => 'Semua', 'url' => '/a', 'active' => true],
            ['label' => 'Berita', 'url' => '/b', 'active' => false],
        ]]);

        expect(preg_match_all('/<a\b[^>]*'.profilClassPattern(['rounded-full']).'/', $chips))->toBe(2);
    });

    it('AC-7: renders the header register link as a pill', function () {
        portalBoot();
        portalPsbOpen();

        $header = profilHeader($this->get('/')->assertOk()->getContent());
        preg_match_all('#<a\b[^>]*href="'.preg_quote(route('psb.register'), '#').'"[^>]*>#', $header, $links);

        expect($links[0])->not->toBeEmpty();

        foreach ($links[0] as $link) {
            expect($link)->toMatch('/'.profilClassPattern(['rounded-full']).'/');
        }
    });

    it('AC-7: defines the shared input class as a pill', function () {
        expect(file_get_contents(resource_path('css/app.css')))->toMatch('/\.ui-input\s*\{[^}]*rounded-full/');
    });

    it('AC-7: renders every visible psb input and select as a pill', function () {
        pendaftaranFixture();
        $expectAllPills = function (array $tags, int $minimum, string $where): void {
            expect(count($tags))->toBeGreaterThanOrEqual($minimum, "{$where}: input tidak ditemukan");

            foreach ($tags as $tag) {
                expect($tag)->toMatch('/'.profilClassPattern(['ui-input']).'/', "{$where}: {$tag}");
            }
        };

        $component = Livewire::test(RegistrationForm::class);
        $expectAllPills(identitasVisibleFields($component->html()), 2, 'langkah 1 (select)');

        $data = pendaftaranData();
        $component->set('unit_id', $data['unit_id'])->set('admission_track_id', $data['admission_track_id'])->set('admission_wave_id', $data['admission_wave_id'])->call('next');
        $expectAllPills(identitasVisibleFields($component->html()), 4, 'langkah 2 (text, date)');

        foreach (['name', 'gender', 'birth_place', 'birth_date', 'nisn', 'previous_school'] as $field) {
            $component->set($field, $data[$field] ?? '');
        }
        $component->call('next');
        $step3 = identitasVisibleFields($component->html());
        $expectAllPills($step3, 3, 'langkah 3 (text, tel, email)');
        expect(implode('', $step3))->toContain('type="tel"')->toContain('type="email"');

        $applicant = pendaftaranApplicant(['registration_no' => 'PSB-2027-0001', 'name' => 'Ahmad Fauzan']);
        app(GenerateRegistrationInvoices::class)->handle($applicant);

        $status = Livewire::test(ApplicationStatus::class);
        $expectAllPills(identitasVisibleFields($status->html()), 2, 'status sebelum masuk');

        pendaftaranPayRegistration($applicant);
        $status->set('registration_no', 'PSB-2027-0001')->set('token', 'ABCD-EFGH-JK23')->call('login');
        $loggedIn = identitasVisibleFields($status->html());
        $expectAllPills($loggedIn, 1, 'status sesudah masuk');
        expect(implode('', $loggedIn))->toContain('type="file"');
    });
});

describe('hiasan', function () {
    beforeEach(function () {
        portalBoot();
    });

    it('AC-9: renders every decoration hidden from assistive technology and small screens', function () {
        $album = portalAlbumWithPhotos(3, ['title' => 'Fasilitas']);
        blkSet([blk('hero', ['title' => 'Judul hero uji', 'album_id' => $album->id]), blk('cta', ['heading' => 'Ajakan uji', 'variant' => 'navy'])]);

        $decorations = identitasDecorations($this->get('/')->assertOk()->getContent());

        foreach ($decorations as [$tag, $inner]) {
            expect($tag)->toContain('aria-hidden="true"')->toMatch('/'.profilClassPattern(['hidden']).'/')
                ->and(trim($inner))->toBe('', 'hiasan harus kosong');
        }
    });

    it('AC-9: adds no images or scripts and keeps the slider in its own section', function () {
        blkSet([blk('hero', ['title' => 'Judul hero uji'])]);
        $baseline = substr_count($this->get('/')->getContent(), '<script');

        $album = portalAlbumWithPhotos(3, ['title' => 'Fasilitas']);
        blkSet([blk('hero', ['title' => 'Judul hero uji', 'album_id' => $album->id])]);
        $html = $this->get('/')->assertOk()->getContent();

        $slider = identitasSlider($html);

        expect($slider)->not->toBe('')
            ->and(preg_match_all('/<img\b/i', $slider))->toBe(3)
            ->and(substr_count($html, '<script'))->toBe($baseline)
            ->and(substr_count($slider, '<section'))->toBe(0);
    });

    it('AC-13: keeps snap and shrink classes on slider photos without arbitrary widths', function () {
        $album = portalAlbumWithPhotos(3, ['title' => 'Fasilitas']);
        blkSet([blk('hero', ['title' => 'Judul hero uji', 'album_id' => $album->id])]);

        preg_match_all('/<img\b[^>]*>/i', identitasSlider($this->get('/')->getContent()), $imgs);

        expect($imgs[0])->toHaveCount(3);

        foreach ($imgs[0] as $img) {
            expect($img)->toMatch('/'.profilClassPattern(['snap-start', 'shrink-0']).'/')
                ->not->toMatch('/class="[^"]*-\[/');
        }
    });
});

describe('aset eksternal', function () {
    it('AC-10: loads no fonts or images from external hosts', function (string $url) {
        portalBoot();
        portalPsbOpen();
        portalSeedContent();
        portalContactData();
        $album = portalAlbumWithPhotos(3, ['title' => 'Fasilitas']);
        identitasLogos([
            'school.logo_desktop_path' => 'school/desktop.png',
            'school.logo_mobile_path' => 'school/mobile.png',
            'school.favicon_path' => 'school/favicon.png',
        ]);
        profilSet(['school.contacts' => [profilContact()]]);
        blkSet([blk('hero', ['title' => 'Judul hero uji', 'album_id' => $album->id]), blk('quote', ['text' => 'نص عربي للاختبار', 'translation' => 'Terjemah uji', 'rtl' => true])]);
        $this->withoutVite();

        $response = $this->get($url);
        expect($response->getStatusCode())->toBe($url === '/tidak-ada-halaman' ? 404 : 200);
        $html = $response->getContent();
        $host = parse_url(config('app.url'), PHP_URL_HOST);

        foreach (['fonts.googleapis', 'fonts.gstatic', 'bunny.net', 'webflow', 'unpkg', 'jsdelivr', 'cdnjs', 'cloudflare'] as $needle) {
            expect($html)->not->toContain($needle);
        }

        preg_match_all('/<link\b[^>]*>/i', $html, $links);

        foreach ($links[0] as $link) {
            if (! preg_match('/\brel="(?:stylesheet|preload)"/', $link) || ! preg_match('/\bhref="(https?:\/\/[^"\/]+)/', $link, $m)) {
                continue;
            }

            expect(parse_url($m[1], PHP_URL_HOST))->toBe($host, "aset luar: {$link}");
        }
    })->with(['/', '/berita', '/berita/wisuda-2026', '/galeri', '/galeri/album-ramadan', '/video', '/kontak', '/psb', '/psb/status', '/tidak-ada-halaman']);
});

describe('layout dan struktur', function () {
    it('AC-11: keeps the home section order of the blocks', function () {
        portalBoot();
        identitasFullHome();

        $html = $this->get('/')->assertOk()->getContent();
        $pos = fn (string $needle): int|false => strpos($html, $needle);

        $markers = ['Judul keunggulan uji', 'Profil Pondok', __('pondok.portal.home.latest_news'), __('pondok.portal.home.latest_achievements'), __('pondok.portal.home.featured_videos'), __('pondok.portal.home.latest_albums'), 'نص عربي للاختبار'];
        $positions = array_map($pos, $markers);

        expect(in_array(false, $positions, true))->toBeFalse('ada seksi yang hilang')
            ->and($positions)->toBe(collect($positions)->sort()->values()->all());

        expect($pos('<h1'))->toBeLessThan($pos('role="region"'))
            ->and($pos('role="region"'))->toBeLessThan($pos('Judul keunggulan uji'))
            ->and(strrpos($html, '<footer'))->toBeGreaterThan($pos('نص عربي للاختبار'))
            ->and(substr_count($html, '<footer'))->toBe(1);
    });

    it('AC-12: still hides empty sections on a fresh install', function () {
        profilFreshInstall();
        Setting::set('modules.konten.enabled', true);

        $html = $this->get('/')->assertOk()->getContent();

        foreach (['role="region"', 'snap-x', '<iframe', 'lang="ar"', '<blockquote', 'seksi-keunggulan', 'seksi-profil'] as $needle) {
            expect($html)->not->toContain($needle);
        }
    });

    it('AC-12: renders only the institution name heading for a new institution', function () {
        profilFreshInstall();
        Setting::set('modules.konten.enabled', true);
        Setting::set('school.name', "Ma'had Al-Khair");

        // Kondisi data kosong dinyatakan eksplisit: tanpa berita, prestasi, album, video, dan halaman profil terbit (satu-satunya halaman yang tampil di beranda).
        expect(Post::query()->count())->toBe(0)
            ->and(Album::query()->count())->toBe(0)
            ->and(Video::query()->count())->toBe(0)
            ->and(StaticPage::query()->where('slug', 'profil')->where('status', 'terbit')->count())->toBe(0);

        $html = $this->get('/')->assertOk()->getContent();
        preg_match('#<main\b[^>]*>(.*?)</main>#is', $html, $main);

        expect($main[1] ?? '')->not->toBe('')
            ->and(preg_match_all('/<h1[\s>]/i', $main[1]))->toBe(1)
            ->and($main[1])->toMatch('/<h1\b[^>]*>\s*'.preg_quote(e("Ma'had Al-Khair"), '/').'\s*<\/h1>/')
            ->and($main[1])->not->toContain('<h2');
    });
});

describe('nama Arab dan logo', function () {
    beforeEach(function () {
        portalBoot();
    });

    // F045 (C3): nama Arab tidak lagi ditampilkan otomatis di beranda; isi hero sepenuhnya dari blok.
    it('AC-14 (F045): does not show the arabic name automatically on the home page', function () {
        Setting::set('school.arabic_name', 'معهد الخير');

        expect($this->get('/')->assertOk()->getContent())->not->toContain('معهد الخير');
    });

    it('AC-14: renders the hgroup without arabic markup when the name is empty', function () {
        $html = $this->get('/')->assertOk()->getContent();
        preg_match_all('#<hgroup\b[^>]*>(.*?)</hgroup>#is', $html, $groups);

        expect($groups[1])->toHaveCount(1)
            ->and($groups[1][0])->toContain('<h1')->not->toContain('lang="ar"');
    });

    it('AC-15: enlarges the header logo for the picture branch', function () {
        $urls = identitasLogos(['school.logo_desktop_path' => 'school/desktop.png', 'school.logo_mobile_path' => 'school/mobile.png']);

        $header = profilHeader($this->get('/')->assertOk()->getContent());
        $img = profilTag($header, 'img', ['src="'.$urls['school.logo_mobile_path'].'"']);

        expect($header)->toContain('<picture')
            ->and($img)->not->toBeNull()
            ->toMatch('/'.profilClassPattern(['h-10', 'max-w-full', 'object-contain']).'/')
            ->toMatch('/class="[^"]*(?<=\s|")md:h-(?:1[2-9]|[2-9]\d)(?=[\s"])/');
    });

    it('AC-15: enlarges the header logo for the desktop only branch', function () {
        $urls = identitasLogos(['school.logo_desktop_path' => 'school/desktop.png']);

        $header = profilHeader($this->get('/')->assertOk()->getContent());
        $img = profilTag($header, 'img', ['src="'.$urls['school.logo_desktop_path'].'"']);

        expect($img)->not->toBeNull()
            ->toMatch('/'.profilClassPattern(['h-10', 'max-w-full', 'object-contain']).'/')
            ->toMatch('/class="[^"]*(?<=\s|")md:h-(?:1[2-9]|[2-9]\d)(?=[\s"])/');
    });
});

describe('Filament', function () {
    it('AC-17: leaves the filament panel on emerald without the portal stylesheet', function () {
        $panel = Filament::getPanel('admin');

        expect($panel->getColors()['primary'])->toEqual(Color::Emerald)
            ->and($panel->getViteTheme())->toBeNull();

        $html = $this->get('/admin/login')->assertOk()->getContent();

        expect($html)->not->toContain('resources/css/app.css')->not->toContain('Plus Jakarta');
    });
});
