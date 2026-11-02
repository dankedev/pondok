<?php

use App\Actions\Konten\GetPortalTheme;
use App\Actions\Konten\ListPortalThemes;
use App\Actions\Konten\ResolveActiveTheme;
use App\Actions\Konten\UpdatePortalTheme;
use App\Actions\Konten\ValidatePortalTheme;
use App\Filament\Pages\Site\Theme;
use App\Models\Setting;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

/*
| F102 VERIFY: percobaan mematahkan validator, renderer, fallback, dan pemilihan tema. Helper diawali `adv` agar tidak
| bentrok dengan helper test lain. Memakai folder tema sementara (`themeInstall`, tests/Pest.php).
*/

beforeEach(function () {
    portalBoot();
    test()->seed(RolePermissionSeeder::class);
    Filament::setCurrentPanel('admin');
});

function advRejection(array|string $manifest, string $dir = THEME_BASE_SLUG): ?string
{
    try {
        app(ValidatePortalTheme::class)->handle(themeTempFile($manifest, $dir));
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }

    return null;
}

/**
 * JSON tema dasar berukuran persis `$bytes` (dipadatkan dengan spasi di ujung).
 */
function advManifestOfSize(int $bytes): string
{
    $json = json_encode(themeBaseManifest());

    return $json.str_repeat(' ', $bytes - strlen($json));
}

function advNested(int $levels): array
{
    return array_reduce(range(1, $levels - 1), fn (array $inner): array => ['component' => 'container', 'children' => [$inner]], ['component' => 'brand']);
}

/**
 * Isi beranda dengan ketujuh tipe blok.
 */
function advSeedHome(): void
{
    portalSeedContent();
    $video = kontenVideo(['title' => 'Video adv']);
    blkSet([
        blk('hero', ['title' => 'Judul hero adv', 'text' => 'Teks hero adv']),
        blk('features', ['heading' => 'Keunggulan adv', 'items' => [['title' => 'Satu', 'text' => 'Deskripsi']]]),
        blk('feed', ['source' => 'albums', 'heading' => 'Feed adv']),
        blk('text_image', ['heading' => 'Tentang adv', 'text' => '<p>Isi.</p>']),
        blk('quote', ['text' => 'Kutipan adv']),
        blk('video', ['heading' => 'Video adv', 'video_id' => $video->id]),
        blk('cta', ['heading' => 'Ajakan adv', 'variant' => 'light']),
    ]);
}

/**
 * Gaya dengan setiap properti terisi.
 *
 * @return array<string, mixed>
 */
function advFullStyle(string $tone): array
{
    return [
        'tone' => $tone,
        'padding' => ['base' => 'lg', 'md' => 'md', 'lg' => 'sm'],
        'gap' => 'lg',
        'align' => 'center',
        'width' => 'full',
        'radius' => 'lg',
        'shadow' => 'sm',
        'border' => 'top',
    ];
}

it('VERIFY AC-1: accepts a file of exactly 64 KB, 150 nodes and depth 6, and rejects one byte, one node and one level more', function () {
    $base = themeBaseManifest();
    $bare = Arr::except($base, ['header', 'footer', 'home']);
    $nodes = fn (int $children): array => [...$bare, 'header' => ['component' => 'container', 'children' => array_fill(0, $children, ['component' => 'brand'])]];

    expect(strlen(advManifestOfSize(65536)))->toBe(65536)
        ->and(advRejection(advManifestOfSize(65536)))->toBeNull('tepat 64 KB')
        ->and(advRejection(advManifestOfSize(65537)))->not->toBeNull('64 KB + 1 byte')
        ->and(advRejection($nodes(149)))->toBeNull('150 node (1 container + 149 anak)')
        ->and(advRejection($nodes(150)))->not->toBeNull('151 node')
        ->and(advRejection([...$bare, 'header' => advNested(6)]))->toBeNull('kedalaman 6')
        ->and(advRejection([...$bare, 'header' => advNested(7)]))->not->toBeNull('kedalaman 7');
});

it('VERIFY AC-1: rejects look-alike, mistyped and nested-wrong values at every level', function () {
    $base = themeBaseManifest();
    $withHeader = fn (array $header): array => [...$base, 'header' => $header];
    $withHero = fn (array $hero): array => themeWith2($base, ['home', 'layout', 0], ['component' => 'hero', ...$hero]);
    $cases = [
        'component array' => $withHeader(['component' => ['container']]),
        'component int 0' => $withHeader(['component' => 0]),
        'component null' => $withHeader(['component' => null]),
        'component trailing space' => $withHeader(['component' => 'brand ']),
        'component trailing newline' => $withHeader(['component' => "brand\n"]),
        'component upper case' => $withHeader(['component' => 'Brand']),
        'component null byte' => $withHeader(['component' => "brand\0"]),
        'component fullwidth look-alike' => $withHeader(['component' => 'ｂrand']),
        'component RTL override' => $withHeader(['component' => "\u{202E}brand"]),
        'component path' => $withHeader(['component' => '../portal/home']),
        'variant array' => $withHero(['variant' => ['split']]),
        'variant case' => $withHero(['variant' => 'Split']),
        'variant int' => $withHero(['variant' => 0]),
        'variant on header node' => $withHeader(['component' => 'brand', 'variant' => 'split']),
        'style as list' => $withHero(['style' => ['light']]),
        'style null' => $withHero(['style' => null]),
        'style property __proto__' => $withHero(['style' => ['__proto__' => ['tone' => 'dark']]]),
        'columns string' => $withHero(['style' => ['columns' => '3']]),
        'columns array' => $withHero(['style' => ['columns' => [3]]]),
        'padding breakpoint null' => $withHero(['style' => ['padding' => ['base' => 'sm', 'md' => null]]]),
        'padding breakpoint array' => $withHero(['style' => ['padding' => ['base' => 'sm', 'md' => ['lg']]]]),
        'padding without base' => $withHero(['style' => ['padding' => ['md' => 'sm']]]),
        'padding empty object' => $withHero(['style' => ['padding' => []]]),
        'tone upper case' => $withHero(['style' => ['tone' => 'Dark']]),
        'tone auto in array' => $withHero(['style' => ['tone' => ['base' => 'auto']]]),
        'unknown key in home' => [...$base, 'home' => ['layout' => [], 'sections' => []]],
        'home.sections from the old schema' => [...$base, 'home' => ['sections' => ['hero' => ['style' => ['tone' => 'dark']]]]],
        'unknown key in layout node' => $withHero(['style' => ['tone' => 'dark'], 'class' => 'x']),
        'unknown key in nested child' => $withHeader(['component' => 'container', 'children' => [['component' => 'brand', 'onclick' => 'x']]]),
        'children not a list' => $withHeader(['component' => 'container', 'children' => ['a' => ['component' => 'brand']]]),
        'children of children on leaf' => $withHeader(['component' => 'container', 'children' => [['component' => 'brand', 'children' => []]]]),
        'footer is a string' => [...$base, 'footer' => 'x'],
        'header is a list' => [...$base, 'header' => [['component' => 'brand']]],
        'layout is an object' => [...$base, 'home' => ['layout' => ['a' => ['component' => 'hero']]]],
        'layout node is a string' => [...$base, 'home' => ['layout' => ['hero']]],
        'schema_version true' => [...$base, 'schema_version' => true],
        'schema_version huge' => [...$base, 'schema_version' => 99999999999999999999],
        'min_core huge' => [...$base, 'min_core' => '99999999999999999999.0.0'],
        'version with prerelease' => [...$base, 'version' => "1.0.0\n"],
        'name only spaces' => [...$base, 'name' => '   '],
        'name array' => [...$base, 'name' => ['x']],
        'name too long' => [...$base, 'name' => str_repeat('a', 61)],
        'slug null byte' => [...$base, 'slug' => "zz-uji-dasar\0"],
        'slug percent-encoded dots' => [...$base, 'slug' => '%2e%2e'],
        'slug trailing newline' => [...$base, 'slug' => "zz-uji-dasar\n"],
    ];

    $accepted = array_keys(array_filter($cases, fn (array $manifest): bool => advRejection($manifest) === null));

    // Kunci duplikat: nilai terakhir menang, jadi komponen akhirnya tak dikenal.
    $duplicate = '{"component":"brand","component":"iframe"}';
    $raw = str_replace(json_encode($base['header']), $duplicate, (string) json_encode($base));

    $floats = [
        'schema_version 1.0' => [...$base, 'schema_version' => 1.0],
        'columns 2.0' => themeWith2($base, ['home', 'layout'], [['component' => 'columns', 'style' => ['columns' => 2.0], 'children' => [['component' => 'hero'], ['component' => 'hero']]]]),
    ];
    $floatAccepted = array_keys(array_filter($floats, fn (array $manifest): bool => advRejection(json_encode($manifest, JSON_PRESERVE_ZERO_FRACTION)) === null));

    expect($accepted)->toBe([], 'Seharusnya ditolak: '.implode('; ', $accepted))
        ->and($floatAccepted)->toBe([], 'bilangan pecahan 1.0/2.0 bukan bilangan bulat')
        ->and(str_contains($raw, 'iframe'))->toBeTrue()
        ->and(advRejection($raw))->not->toBeNull('kunci duplikat');
});

function themeWith2(array $manifest, array $path, mixed $value): array
{
    data_set($manifest, implode('.', $path), $value);

    return $manifest;
}

it('VERIFY AC-1: keeps a hostile file from echoing itself into the error message', function () {
    $base = themeBaseManifest();
    $huge = str_repeat('x', 50000);
    $cases = [
        'schema_version panjang' => [...$base, 'schema_version' => $huge],
        'nilai gaya panjang' => themeWith2($base, ['header', 'style', 'gap'], $huge),
        'komponen panjang' => themeWith2($base, ['header', 'children', 0, 'component'], $huge),
        'kunci tak dikenal banyak' => [...$base, ...array_combine(array_map(fn (int $i): string => "kunci{$i}".str_repeat('k', 200), range(1, 200)), array_fill(0, 200, 1))],
    ];

    $lengths = array_map(fn (array $manifest): int => mb_strlen((string) advRejection($manifest)), $cases);

    expect(array_filter($lengths, fn (int $length): bool => $length > 1000))->toBe([], 'pesan galat tak dibatasi (dipakai di halaman Tampilan dan log): '.json_encode($lengths));
});

it('VERIFY AC-15: never silently downgrades a valid theme, with every style property on every node and tone', function () {
    advSeedHome();
    Log::spy();
    $base = themeBaseManifest();

    foreach (['light', 'muted', 'brand', 'dark', 'auto'] as $tone) {
        $style = advFullStyle($tone);
        $childStyle = [...$style, 'tone' => $tone === 'auto' ? 'light' : $tone];
        $header = ['component' => 'container', 'style' => [...$style, 'tone' => $tone === 'auto' ? 'light' : $tone], 'children' => [['component' => 'brand', 'style' => $childStyle], ['component' => 'nav_menu', 'style' => $childStyle]]];
        $footer = ['component' => 'container', 'style' => [...$style, 'tone' => $tone === 'auto' ? 'dark' : $tone], 'children' => [['component' => 'footer_widgets', 'style' => $childStyle], ['component' => 'copyright', 'style' => $childStyle]]];
        $layout = array_map(fn (string $component): array => ['component' => $component, 'style' => $style], ['hero', 'text_image', 'feature_grid', 'quote', 'cta', 'video', 'feed_list']);
        $layout[] = ['component' => 'columns', 'style' => [...$style, 'columns' => ['base' => 1, 'md' => 2, 'lg' => 2]], 'children' => [['component' => 'quote'], ['component' => 'cta']]];
        $slug = themeInstall([...$base, 'header' => $header, 'footer' => $footer, 'home' => ['layout' => $layout]]);
        themeActivate($slug);

        $html = blkHome();

        expect($html)->toContain('Judul hero adv')->toContain('Ajakan adv');
    }

    Log::shouldNotHaveReceived('warning');
});

it('VERIFY AC-15: either rejects tone auto outside top-level home.layout nodes or renders it, never falling back after a failed render', function () {
    advSeedHome();
    Log::spy();
    $base = themeBaseManifest();
    $variants = [
        'header' => ['header' => ['component' => 'container', 'style' => ['tone' => 'auto'], 'children' => [['component' => 'brand'], ['component' => 'nav_menu']]]],
        'footer' => ['footer' => ['component' => 'container', 'children' => [['component' => 'footer_widgets', 'style' => ['tone' => 'auto']], ['component' => 'copyright']]]],
    ];

    foreach ($variants as $label => $override) {
        $manifest = [...$base, ...$override];

        if (advRejection($manifest) !== null) {
            continue;
        }

        themeActivate(themeInstall($manifest));
        blkHome();

        Log::shouldNotHaveReceived('warning');
        expect(true)->toBeTrue($label);
    }
});

it('VERIFY AC-6: keeps odd stored slugs away from the filesystem and the chosen theme', function () {
    Log::spy();
    $odd = ['../portal/home', "centered\n", "majalah\0", 'MAJALAH', ' majalah', '..', '%2e%2e', 'a', str_repeat('a', 41), 'tidak-ada-folder', ['majalah'], 7, true];

    foreach ($odd as $value) {
        Setting::set('theme.template', $value);
        app()->forgetScopedInstances();

        expect(app(GetPortalTheme::class)->template())->toBe('default', json_encode($value))
            ->and(app(GetPortalTheme::class)->handle()['template'])->toBe('default', json_encode($value))
            ->and(app(ResolveActiveTheme::class)->handle())->toBeNull(json_encode($value));
    }

    // Folder ada tetapi tanpa theme.json: bukan tema terpasang.
    $dir = themeSandbox().'/zz-tanpa-manifest';
    mkdir($dir);
    Setting::set('theme.template', 'zz-tanpa-manifest');
    app()->forgetScopedInstances();

    expect(app(GetPortalTheme::class)->template())->toBe('default')
        ->and(app(ResolveActiveTheme::class)->handle())->toBeNull();

    // Slug rusak sangat panjang tidak membengkakkan log.
    Setting::set('theme.template', str_repeat('z', 5000).'/../x');
    app()->forgetScopedInstances();
    app(ResolveActiveTheme::class)->handle();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => mb_strlen((string) ($context['slug'] ?? '')) <= 100)->atLeast()->once();
    Log::shouldNotHaveReceived('warning', fn (string $message, array $context = []): bool => mb_strlen(json_encode($context)) > 2000);
});

it('VERIFY AC-6: refuses odd values from UpdatePortalTheme without touching the stored theme', function () {
    $user = kontenUser('admin-konten');
    $broken = themeInstall([...themeBaseManifest(), 'schema_version' => 99]);
    Setting::set('theme.template', 'centered');
    $accepted = [];

    foreach (['../default', "majalah\n", 'MAJALAH', ' majalah', 'majalah ', "majalah\0", ['majalah'], 1, true, '0', $broken, 'tidak-ada'] as $value) {
        try {
            app(UpdatePortalTheme::class)->handle(['template' => $value], $user);
            $accepted[] = json_encode($value);
        } catch (Throwable) {
        }
    }

    expect($accepted)->toBe([])
        ->and(Setting::get('theme.template'))->toBe('centered');
});

it('VERIFY AC-6: escapes theme name, description and failure reason on the Tampilan page', function () {
    $base = themeBaseManifest();
    $hostile = themeInstall([...$base, 'name' => '<script>alert(1)</script>Tema', 'description' => '<img src=x onerror=alert(2)>']);
    themeInstall([...$base, '<b onmouseover=alert(3)>' => 1]);
    mkdir(themeSandbox().'/Ada Spasi & <i>');
    $this->actingAs(kontenUser('admin-konten'));

    $component = Livewire::test(Theme::class);
    $html = $component->html();

    expect($html)->not->toContain('<script>alert(1)')->not->toContain('<img src=x onerror')->not->toContain('<b onmouseover')->not->toContain('<i>')
        ->and($html)->toContain('Tema');

    $component->fillForm(['template' => $hostile])->call('save')->assertHasNoErrors();

    expect(Setting::get('theme.template'))->toBe($hostile);
});

it('VERIFY AC-6: lets a valid theme with an all-digit slug be chosen on the Tampilan page', function () {
    themeInstall(themeBaseManifest(), '12345');
    $this->actingAs(kontenUser('admin-konten'));

    Livewire::test(Theme::class)->fillForm(['template' => '12345'])->call('save')->assertHasNoErrors();

    expect(Setting::get('theme.template'))->toBe('12345');
});

it('VERIFY AC-6: tolerates odd entries in the themes directory', function () {
    $root = themeSandbox();
    file_put_contents("{$root}/berkas-biasa.txt", 'x');
    mkdir("{$root}/KosongTanpaManifest");
    symlink("{$root}/centered", "{$root}/tautan-centered");
    symlink("{$root}/tidak-ada-sasaran", "{$root}/tautan-putus");
    $entries = collect(app(ListPortalThemes::class)->handle())->keyBy('slug');
    $valid = $entries->where('valid', true)->keys()->sort()->values()->all();

    expect($valid)->toBe(['centered', 'default', 'majalah', 'mushaf'], 'tautan simbolik ke tema lain tidak jadi tema ber-slug berbeda')
        ->and($entries->has('berkas-biasa.txt'))->toBeFalse()
        ->and($entries['KosongTanpaManifest']['valid'])->toBeFalse();

    Setting::set('theme.template', 'tautan-centered');
    app()->forgetScopedInstances();

    expect(app(ResolveActiveTheme::class)->handle())->toBeNull('slug manifest (centered) tidak sama dengan nama direktori tautan');
});

it('VERIFY AC-7: keeps every themed portal route stateless and cacheable with majalah, mushaf and a broken theme active', function () {
    advSeedHome();
    Setting::set('modules.psb.enabled', true);
    psbInfoPage(['status' => 'terbit']);
    $post = portalPost(['title' => 'Berita adv']);
    $album = portalAlbumWithPhotos(1, ['title' => 'Album adv']);
    $urls = ['/', '/berita', "/berita/{$post->slug}", '/galeri', "/galeri/{$album->slug}", '/video', '/profil', route('psb.info', absolute: false)];

    foreach (['majalah', 'mushaf', themeUniqueSlug()] as $slug) {
        themeActivate($slug);

        foreach ($urls as $url) {
            $response = $this->get($url)->assertOk();
            $html = strtolower($response->getContent());

            expect($response->headers->get('Cache-Control'))->toContain('public')->toContain('max-age')
                ->and($response->headers->get('ETag'))->not->toBeNull("{$slug} {$url}")
                ->and($response->headers->getCookies())->toBeEmpty("{$slug} {$url}")
                ->and($html)->not->toContain('csrf-token', "{$slug} {$url}")->not->toContain('livewire', "{$slug} {$url}")->not->toContain('xsrf', "{$slug} {$url}")
                ->and(substr_count($html, '<main'))->toBe(1, "{$slug} {$url}");
        }
    }
});

it('VERIFY AC-7: does not theme the sitemap and robots, and keeps 404 when the content module is off', function () {
    themeActivate('mushaf');

    $this->get('/sitemap.xml')->assertOk();
    expect($this->get('/sitemap.xml')->headers->get('Content-Type'))->toContain('application/xml');
    expect($this->get('/robots.txt')->assertOk()->getContent())->not->toContain('<header');

    Setting::set('modules.konten.enabled', false);

    $this->get('/berita')->assertNotFound();
    $this->get('/galeri')->assertNotFound();
});

it('VERIFY AC-8: falls back at once when an active theme is corrupted or deleted, and recovers when restored', function () {
    advSeedHome();
    $core = themeChrome2(blkHome());
    $slug = themeInstall(themeBaseManifest());
    themeActivate($slug);
    $themed = themeChrome2(blkHome());
    $file = themeSandbox()."/{$slug}/theme.json";
    $good = file_get_contents($file);

    file_put_contents($file, '{"schema_version":');
    app()->forgetScopedInstances();
    $corrupted = themeChrome2(blkHome());

    File::deleteDirectory(dirname($file));
    app()->forgetScopedInstances();
    $deleted = themeChrome2(blkHome());

    mkdir(dirname($file));
    file_put_contents($file, $good);
    app()->forgetScopedInstances();

    expect($themed)->not->toBe($core)
        ->and($corrupted)->toBe($core)
        ->and($deleted)->toBe($core)
        ->and(themeChrome2(blkHome()))->toBe($themed)
        ->and(app(GetPortalTheme::class)->template())->toBe($slug);
});

it('VERIFY AC-3: adds no settings query for the theme on the portal home page', function () {
    advSeedHome();
    $count = function (): int {
        app()->forgetScopedInstances();
        blkHome();
        DB::flushQueryLog();
        DB::enableQueryLog();
        blkHome();

        return collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "settings"') || str_contains($query['query'], 'from `settings`'))->count();
    };

    themeActivate(null);
    $none = $count();
    themeActivate('mushaf');
    $base = $count();
    themeActivate('centered');

    expect($base)->toBe($none)->and($count())->toBe($none);
});

function themeChrome2(string $html): array
{
    preg_match('#<header\b.*?</header>#is', $html, $header);
    preg_match('#<footer\b.*?</footer>#is', $html, $footer);

    return [preg_replace('/\s+/', ' ', $header[0] ?? ''), preg_replace('/\s+/', ' ', $footer[0] ?? '')];
}
