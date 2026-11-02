<?php

use App\Actions\Konten\ValidatePortalTheme;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

/*
| F102 AC-1, AC-2, AC-3 dan bagian arsitektur AC-9. Kontrak yang diasumsikan test:
|  - `ValidatePortalTheme::handle(string $path): array` menerima path berkas `theme.json` (nama direktori induknya = slug),
|    mengembalikan pohon ternormalisasi (memuat `slug`, `name`, `description`, dan `home.layout` = daftar node), dan melempar
|    `InvalidArgumentException` berpesan alasan untuk semua yang ditolak. Ukuran dicek sebelum `json_decode`.
|  - Tema resmi di `resources/themes/{default,centered,majalah,mushaf}/theme.json`.
|  - Node = `{component, variant?, style?, children?}`; `home.layout` = daftar node (urutan bebas); `home.sections` tidak lagi sah.
|  - `style.columns` = 1|2|3|4 (responsif `{base, md, lg}` boleh), hanya pada `columns`; `tone: auto` hanya pada node level atas `home.layout`.
|  - Tanpa `home.layout`, pohon ternormalisasi memuat `[['component' => 'blocks', 'variant' => 'default']]`.
|  - Mutasi bertumpu pada `themeBaseManifest()` (tests/Pest.php), bukan pada berkas di disk.
*/

function themeRejection(array|string $manifest, ?string $dir = null): ?string
{
    try {
        app(ValidatePortalTheme::class)->handle(themeTempFile($manifest, $dir));
    } catch (InvalidArgumentException $e) {
        return $e->getMessage() === '' ? '(tanpa pesan)' : $e->getMessage();
    }

    return null;
}

/**
 * @param  array<string, mixed>  $manifest
 */
function themeWith(array $manifest, string $path, mixed $value): array
{
    data_set($manifest, $path, $value);

    return $manifest;
}

/**
 * @param  array<string, mixed>  $manifest
 */
function themeAppend(array $manifest, string $path, mixed $item): array
{
    $list = data_get($manifest, $path, []);
    $list[] = $item;
    data_set($manifest, $path, $list);

    return $manifest;
}

it('AC-1: accepts the four official themes and returns a normalised tree', function () {
    foreach (THEME_OFFICIAL as $slug) {
        $tree = app(ValidatePortalTheme::class)->handle(resource_path("themes/{$slug}/theme.json"));

        expect($tree)->toBeArray()->toHaveKeys(['slug', 'name', 'description'])
            ->and($tree['slug'])->toBe($slug)
            ->and($tree['home']['layout'])->toBeArray()->not->toBeEmpty()
            // Kontrol harness: salinan sah di direktori sementara juga diterima, jadi penolakan di bawah memang karena mutasinya.
            ->and(themeRejection(themeOfficialManifest($slug), $slug))->toBeNull("salinan {$slug}");
    }

    expect(array_diff(array_map('basename', glob(resource_path('themes/*'))), THEME_OFFICIAL))->toBe([], 'klasik dibuang; hanya empat tema resmi');
});

it('AC-1: accepts the free-order layout of the base theme, and defaults a missing home.layout to the blocks preset', function () {
    $base = themeBaseManifest();
    $free = themeLayout([
        ['component' => 'quote'],
        ['component' => 'container', 'children' => [['component' => 'columns', 'style' => ['columns' => ['base' => 1, 'md' => 2]], 'children' => [['component' => 'feed_list'], ['component' => 'hero']]]]],
        ['component' => 'divider'],
        ['component' => 'feed_list', 'variant' => 'magazine'],
        ['component' => 'hero', 'variant' => 'split'],
    ]);
    $bare = Arr::except($base, ['home']);
    $tree = app(ValidatePortalTheme::class)->handle(themeTempFile($bare));
    $blocksCentered = themeLayout([['component' => 'blocks', 'variant' => 'centered']]);

    expect(themeRejection($base))->toBeNull()
        ->and(themeRejection($free))->toBeNull('susunan bebas: quote paling atas, hero di bawah, feed_list berulang, columns di container')
        ->and(themeRejection(themeWith($base, 'header', ['component' => 'columns', 'children' => [['component' => 'brand'], ['component' => 'nav_menu']]])))->toBeNull('columns + brand + nav_menu di header')
        ->and(themeRejection(themeWith($base, 'footer', ['component' => 'container', 'children' => [['component' => 'divider'], ['component' => 'copyright']]])))->toBeNull('divider di footer')
        ->and(themeRejection($blocksCentered))->toBeNull('blocks centered satu-satunya node')
        ->and($tree['home']['layout'])->toBe([['component' => 'blocks', 'variant' => 'default']]);
});

it('AC-1: rejects manifests that break the closed schema', function () {
    $base = themeBaseManifest();
    $hero = ['component' => 'hero'];
    $cases = [
        'tanpa schema_version' => Arr::except($base, 'schema_version'),
        'schema_version 99' => [...$base, 'schema_version' => 99],
        'schema_version 0' => [...$base, 'schema_version' => 0],
        'schema_version string' => [...$base, 'schema_version' => '1'],
        'kunci puncak tak dikenal' => [...$base, 'script' => 'alert(1)'],
        'assets' => [...$base, 'assets' => ['css' => ['x.css']]],
        'home.sections lama' => [...$base, 'home' => ['sections' => ['hero' => ['style' => ['tone' => 'dark']]]]],
        'home.sections di samping layout' => themeWith($base, 'home.sections', []),
        'kunci tak dikenal di home' => themeWith($base, 'home.hero', []),
        'layout objek bukan daftar' => themeWith($base, 'home.layout', ['a' => $hero]),
        'layout string' => themeWith($base, 'home.layout', 'hero'),
        'tanpa name' => Arr::except($base, 'name'),
        'version bukan semver' => [...$base, 'version' => 'satu'],
        'min_core bukan semver' => [...$base, 'min_core' => 'baru'],
        'komponen layout tak dikenal' => themeAppend($base, 'home.layout', ['component' => 'iframe']),
        'komponen lama section' => themeAppend($base, 'home.layout', ['component' => 'section']),
        'komponen lama stack' => themeAppend($base, 'home.layout', ['component' => 'stack']),
        'komponen lama spacer' => themeAppend($base, 'home.layout', ['component' => 'spacer']),
        'komponen header tak dikenal' => themeAppend($base, 'header.children', ['component' => 'iframe']),
        'komponen footer tak dikenal' => themeAppend($base, 'footer.children', ['component' => 'script']),
        'footer_widgets di header' => themeAppend($base, 'header.children', ['component' => 'footer_widgets']),
        'copyright di header' => themeAppend($base, 'header.children', ['component' => 'copyright']),
        'nav_menu di footer' => themeAppend($base, 'footer.children', ['component' => 'nav_menu']),
        'brand di footer' => themeAppend($base, 'footer.children', ['component' => 'brand']),
        'hero di header' => themeAppend($base, 'header.children', $hero),
        'hero di footer' => themeAppend($base, 'footer.children', $hero),
        'blocks di header' => themeWith($base, 'header', ['component' => 'blocks']),
        'brand di home' => themeAppend($base, 'home.layout', ['component' => 'brand']),
        'nav_menu di home' => themeAppend($base, 'home.layout', ['component' => 'nav_menu']),
        'footer_widgets di home' => themeAppend($base, 'home.layout', ['component' => 'footer_widgets']),
        'copyright di home' => themeAppend($base, 'home.layout', ['component' => 'copyright']),
        'kunci node tak dikenal' => themeWith($base, 'home.layout.0.html', '<b>x</b>'),
        'children pada hero' => themeWith($base, 'home.layout.0.children', []),
        'children pada divider' => themeAppend($base, 'home.layout', ['component' => 'divider', 'children' => [$hero]]),
        'children pada brand di header' => themeWith($base, 'header.children.0.children', []),
        'children bukan daftar' => themeWith($base, 'header.children', ['a' => ['component' => 'brand']]),
        'columns dengan 1 anak' => themeLayout([['component' => 'columns', 'children' => [$hero]]]),
        'columns tanpa anak' => themeLayout([['component' => 'columns']]),
        'columns dengan 5 anak' => themeLayout([['component' => 'columns', 'children' => array_fill(0, 5, $hero)]]),
        'style.columns lebih besar dari jumlah anak' => themeLayout([['component' => 'columns', 'style' => ['columns' => 3], 'children' => [$hero, $hero]]]),
        'style.columns responsif lebih besar dari jumlah anak' => themeLayout([['component' => 'columns', 'style' => ['columns' => ['base' => 1, 'md' => 4]], 'children' => [$hero, $hero]]]),
        'style.columns pada container' => themeLayout([['component' => 'container', 'style' => ['columns' => 2], 'children' => [$hero]]]),
        'style.columns pada hero' => themeLayout([['component' => 'hero', 'style' => ['columns' => 2]]]),
        'blocks bersarang di container' => themeLayout([['component' => 'container', 'children' => [['component' => 'blocks']]]]),
        'blocks bersarang di columns' => themeLayout([['component' => 'columns', 'children' => [['component' => 'blocks'], $hero]]]),
        'blocks bersama node lain (blocks dulu)' => themeLayout([['component' => 'blocks'], $hero]),
        'blocks bersama node lain (blocks belakangan)' => themeLayout([$hero, ['component' => 'blocks']]),
        'blocks ganda' => themeLayout([['component' => 'blocks'], ['component' => 'blocks']]),
        'blocks bergaya' => themeLayout([['component' => 'blocks', 'style' => ['tone' => 'dark']]]),
        'blocks bergaya kosong' => themeLayout([['component' => 'blocks', 'style' => []]]),
        'blocks berchildren' => themeLayout([['component' => 'blocks', 'children' => [$hero]]]),
        'blocks bervarian center' => themeLayout([['component' => 'blocks', 'variant' => 'center']]),
        'blocks bervarian magazine' => themeLayout([['component' => 'blocks', 'variant' => 'magazine']]),
        'hero image_bg' => themeLayout([['component' => 'hero', 'variant' => 'image_bg']]),
        'hero varian huruf besar' => themeLayout([['component' => 'hero', 'variant' => 'Center']]),
        'hero varian array' => themeLayout([['component' => 'hero', 'variant' => ['center']]]),
        'feed_list varian center' => themeLayout([['component' => 'feed_list', 'variant' => 'center']]),
        'quote varian cards' => themeLayout([['component' => 'quote', 'variant' => 'cards']]),
        'cta varian framed' => themeLayout([['component' => 'cta', 'variant' => 'framed']]),
        'feature_grid varian list' => themeLayout([['component' => 'feature_grid', 'variant' => 'list']]),
        'divider bervarian' => themeLayout([['component' => 'divider', 'variant' => 'line']]),
        'divider bervarian ornament' => themeLayout([['component' => 'divider', 'variant' => 'ornament']]),
        'text_image bervarian' => themeLayout([['component' => 'text_image', 'variant' => 'split']]),
        'video bervarian' => themeLayout([['component' => 'video', 'variant' => 'split']]),
        'columns bervarian' => themeLayout([['component' => 'columns', 'variant' => 'split', 'children' => [['component' => 'hero'], ['component' => 'hero']]]]),
        'container bervarian' => themeLayout([['component' => 'container', 'variant' => 'split', 'children' => [['component' => 'hero']]]]),
        'varian di header' => themeWith($base, 'header.children.0.variant', 'default'),
        'tone auto bersarang di container' => themeLayout([['component' => 'container', 'children' => [['component' => 'hero', 'style' => ['tone' => 'auto']]]]]),
        'tone auto bersarang di kolom' => themeLayout([['component' => 'columns', 'children' => [['component' => 'hero', 'style' => ['tone' => 'auto']], ['component' => 'quote']]]]),
        'tone auto di header' => themeWith($base, 'header.style.tone', 'auto'),
        'tone auto di anak header' => themeWith($base, 'header.children.0.style', ['tone' => 'auto']),
        'tone auto di footer' => themeWith($base, 'footer.style.tone', 'auto'),
        'tone auto di anak footer' => themeWith($base, 'footer.children.1.style', ['tone' => 'auto']),
        'slug berbeda dari direktori' => [...$base, 'slug' => 'lain'],
        'slug huruf besar' => [...$base, 'slug' => 'Dasar'],
        'slug terlalu pendek' => [...$base, 'slug' => 'k'],
        'slug terlalu panjang' => [...$base, 'slug' => str_repeat('a', 41)],
        'slug dengan path' => [...$base, 'slug' => '../dasar'],
    ];

    $accepted = [];

    foreach ($cases as $label => $manifest) {
        if (themeRejection($manifest) === null) {
            $accepted[] = $label;
        }
    }

    expect(themeRejection($base))->toBeNull('kontrol: manifest dasar sah')
        ->and(themeRejection($base, 'beda-nama'))->not->toBeNull('slug dasar di direktori beda-nama')
        ->and($accepted)->toBe([], 'Manifest ini seharusnya ditolak: '.implode('; ', $accepted));
});

it('AC-1: rejects a broken or hostile file before trusting it', function () {
    $valid = json_encode(themeBaseManifest());
    $nested = str_repeat('{"a":', 1000).'1'.str_repeat('}', 1000);
    $cases = [
        'JSON terpotong' => '{"schema_version":1,',
        'berkas kosong' => '',
        'akar bukan objek (daftar)' => '[]',
        'akar null' => 'null',
        'akar string' => '"dasar"',
        'UTF-8 rusak di nilai' => preg_replace('/"name":"/', '"name":"Rusak '."\xB1\x31".'', $valid, 1),
        'JSON bersarang ekstrem (1000 tingkat)' => $nested,
        'lebih dari 64 KB (JSON sah diberi spasi)' => $valid.str_repeat(' ', 70 * 1024),
    ];

    $accepted = array_keys(array_filter($cases, fn (string $raw): bool => themeRejection($raw) === null));

    expect(themeRejection($valid))->toBeNull('kontrol: JSON dasar sah')
        ->and($accepted)->toBe([], 'Berkas ini seharusnya ditolak: '.implode('; ', $accepted))
        ->and(fn () => app(ValidatePortalTheme::class)->handle(sys_get_temp_dir().'/tidak-ada-f102/zz-uji-dasar/theme.json'))->toThrow(InvalidArgumentException::class);
});

it('AC-2: accepts depth 6 and 150 nodes, rejects depth 7 and 151 nodes, naming tree depth in the message', function () {
    $base = themeBaseManifest();
    $bare = Arr::except($base, ['header', 'footer', 'home']);
    // home.layout level atas = kedalaman 1.
    $layoutDepth = fn (int $levels): array => themeLayout([themeNested($levels, ['component' => 'hero'])], $bare);
    $nodes = fn (int $total): array => themeLayout([['component' => 'container', 'children' => array_fill(0, $total - 1, ['component' => 'hero'])]], $bare);
    $split = [...$bare, 'header' => ['component' => 'container', 'children' => array_fill(0, 49, ['component' => 'brand'])], 'footer' => ['component' => 'container', 'children' => array_fill(0, 49, ['component' => 'copyright'])], 'home' => ['layout' => [['component' => 'container', 'children' => array_fill(0, 49, ['component' => 'hero'])]]]];

    expect(themeRejection($layoutDepth(6)))->toBeNull('kedalaman 6 di layout')
        ->and(themeRejection($layoutDepth(7)))->toMatch('/kedalaman|tingkat|dalam|depth/i')
        ->and(themeRejection(themeWith($bare, 'header', themeNested(6))))->toBeNull('kedalaman 6 di header')
        ->and(themeRejection(themeWith($bare, 'header', themeNested(7))))->not->toBeNull('kedalaman 7 di header')
        ->and(themeRejection($nodes(150)))->toBeNull('150 node di layout')
        ->and(themeRejection($nodes(151)))->not->toBeNull('151 node di layout')
        ->and(themeRejection($split))->toBeNull('150 node total header+footer+layout (3 x 50)')
        ->and(themeRejection(themeAppend($split, 'home.layout', ['component' => 'divider'])))->not->toBeNull('151 node total');
});

it('AC-3: rejects style values outside the enums, including injection strings and responsive on the wrong property', function () {
    $badValues = ['url(https://x.test/a.png)', 'expression(alert(1))', '@import "x"', 'light;', 'light}', 'javascript:alert(1)', '<script>alert(1)</script>', '#ff0000', 'red', 'lg ', 'LG', 5, true];
    $base = themeBaseManifest();
    $cases = [
        'responsif pada tone' => themeWith($base, 'home.layout.0.style', ['tone' => ['base' => 'light', 'md' => 'dark']]),
        'responsif pada shadow' => themeWith($base, 'home.layout.0.style', ['shadow' => ['base' => 'none', 'lg' => 'sm']]),
        'responsif pada gap' => themeWith($base, 'home.layout.0.style', ['gap' => ['base' => 'sm', 'md' => 'lg']]),
        'responsif pada width' => themeWith($base, 'home.layout.0.style', ['width' => ['base' => 'narrow', 'md' => 'wide']]),
        'kunci breakpoint tak dikenal' => themeWith($base, 'home.layout.0.style', ['padding' => ['base' => 'sm', 'xl' => 'lg']]),
        'properti gaya tak dikenal color' => themeWith($base, 'home.layout.0.style', ['color' => 'red']),
        'properti gaya tak dikenal background' => themeWith($base, 'home.layout.0.style', ['background' => 'url(x)']),
        'padding di luar enum' => themeWith($base, 'home.layout.0.style', ['padding' => 'xl']),
        'columns 5' => themeLayout([['component' => 'columns', 'style' => ['columns' => 5], 'children' => array_fill(0, 4, ['component' => 'hero'])]]),
        'columns 0' => themeLayout([['component' => 'columns', 'style' => ['columns' => 0], 'children' => [['component' => 'hero'], ['component' => 'hero']]]]),
        'columns string' => themeLayout([['component' => 'columns', 'style' => ['columns' => '2'], 'children' => [['component' => 'hero'], ['component' => 'hero']]]]),
        'columns pecahan' => themeLayout([['component' => 'columns', 'style' => ['columns' => 2.5], 'children' => [['component' => 'hero'], ['component' => 'hero']]]]),
        'tone auto responsif' => themeWith($base, 'home.layout.0.style', ['tone' => ['base' => 'auto']]),
    ];

    foreach ($badValues as $index => $value) {
        $cases['tone #'.$index.' '.json_encode($value)] = themeWith($base, 'home.layout.0.style', ['tone' => $value]);
        $cases['gap header #'.$index.' '.json_encode($value)] = themeWith($base, 'header.style', ['gap' => $value]);
    }

    $accepted = array_keys(array_filter($cases, fn (array $manifest): bool => themeRejection($manifest) === null));
    $valid = themeLayout([['component' => 'columns', 'style' => ['columns' => ['base' => 1, 'md' => 4], 'gap' => 'sm'], 'children' => array_fill(0, 4, ['component' => 'hero'])]]);

    expect(themeRejection($valid))->toBeNull('kontrol: columns 1 base dan 4 md sah')
        ->and($accepted)->toBe([], 'Seharusnya ditolak: '.implode('; ', $accepted));
});

it('AC-9: ships only theme.json files, with no code, markup or assets, in the four official theme directories', function () {
    $files = collect(File::allFiles(resource_path('themes')))
        ->map(fn (SplFileInfo $file): string => str_replace(resource_path('themes').'/', '', $file->getPathname()))
        ->sort()->values()->all();

    expect($files)->toBe(['centered/theme.json', 'default/theme.json', 'majalah/theme.json', 'mushaf/theme.json']);
});

it('AC-9: never turns theme json into a view name, a view include or an unmapped class attribute', function () {
    $sources = collect([
        ...glob(app_path('Actions/Konten/*Theme*.php')) ?: [],
        ...glob(resource_path('views/portal/theme/*.blade.php')) ?: [],
        ...glob(resource_path('views/portal/theme/**/*.blade.php')) ?: [],
        ...glob(resource_path('views/components/portal/theme-node.blade.php')) ?: [],
    ])->reject(fn (string $path): bool => str_ends_with($path, 'GetPortalTheme.php') || str_ends_with($path, 'UpdatePortalTheme.php'))->unique()->values();

    $views = $sources->filter(fn (string $path): bool => str_ends_with($path, '.blade.php'));
    $problems = [];

    foreach ($sources as $path) {
        $code = (string) file_get_contents($path);
        $code = preg_replace('/\{\{--.*?--\}\}/s', '', $code);
        $code = str_ends_with($path, '.php') && ! str_ends_with($path, '.blade.php')
            ? implode('', array_map(fn ($token): string => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : (is_array($token) ? $token[1] : $token), token_get_all($code)))
            : $code;

        foreach ([
            '/@(?:include|includeIf|includeWhen|includeUnless|includeFirst|each)\s*\(\s*(?!["\'][a-z0-9_.\-]+["\']\s*[,)])/i' => 'include dengan nama view dinamis',
            '/\bview\s*\(\s*(?!["\'][a-z0-9_.\-]+["\']\s*[,)])/i' => 'view() dengan nama dinamis',
            '/View::(?:make|first|exists)\s*\(\s*(?!["\'][a-z0-9_.\-]+["\']\s*[,)])/i' => 'View:: dengan nama dinamis',
            '/<x-dynamic-component/i' => 'x-dynamic-component',
            '/\{!!\s*\$node/' => 'keluaran mentah dari node',
        ] as $pattern => $reason) {
            if (preg_match($pattern, $code) === 1) {
                $problems[] = basename($path).": {$reason}";
            }
        }

        foreach (preg_split('/\R/', $code) as $line) {
            if (str_ends_with($path, '.blade.php') && preg_match('/\[\s*[\'"]style[\'"]\s*\]/', $line) === 1 && ! str_contains($line, 'ThemeStyleMap')) {
                $problems[] = basename($path).': $node[\'style\'] dipakai tanpa ThemeStyleMap -> '.trim($line);
            }
        }
    }

    expect($views)->not->toBeEmpty('view komponen tema (resources/views/portal/theme/*.blade.php dan components/portal/theme-node.blade.php) belum ada')
        ->and($problems)->toBe([]);
});
