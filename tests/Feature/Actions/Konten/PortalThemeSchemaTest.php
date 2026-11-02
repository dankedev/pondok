<?php

use App\Actions\Konten\ValidatePortalTheme;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

/*
| F102 AC-1 dan bagian arsitektur AC-9. Kontrak yang diasumsikan test:
|  - `ValidatePortalTheme::handle(string $path): array` menerima path berkas `theme.json` (nama direktori induknya = slug),
|    mengembalikan pohon ternormalisasi (memuat setidaknya `slug`, `name`, `description`), dan melempar
|    `InvalidArgumentException` (atau turunannya) berpesan alasan untuk semua yang ditolak. Ukuran dicek sebelum `json_decode`.
|  - Tema resmi di `resources/themes/{default,centered,klasik}/theme.json`.
|  - Struktur: `header` dan `footer` = node `{component, variant?, style?, children?}` dengan `children` hanya pada `container`;
|    `home.sections.<tipe_blok>` = `{variant?, style?}`; `style` berisi properti enum, `columns` berupa bilangan bulat (2|3|4).
|  - Tema klasik memuat `header.children` dan `footer.children`, dan satu seksi `hero` di `home.sections`; mutasi di bawah bertumpu pada itu.
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

it('AC-1: accepts the three official themes and returns a normalised tree', function () {
    foreach (['default', 'centered', 'klasik'] as $slug) {
        $tree = app(ValidatePortalTheme::class)->handle(resource_path("themes/{$slug}/theme.json"));

        expect($tree)->toBeArray()->toHaveKeys(['slug', 'name', 'description'])
            ->and($tree['slug'])->toBe($slug)
            // Kontrol harness: salinan sah di direktori sementara juga diterima, jadi penolakan di bawah memang karena mutasinya.
            ->and(themeRejection(themeOfficialManifest($slug), $slug))->toBeNull("salinan {$slug}");
    }
});

it('AC-1: rejects manifests that break the closed schema', function () {
    $klasik = themeOfficialManifest('klasik');
    $badStyleValues = ['url(https://x.test/a.png)', 'expression(alert(1))', '@import "x"', 'light;', 'light}', 'javascript:alert(1)', '<script>alert(1)</script>', '#ff0000', 'red', 'lg ', 'LG', 5, true];
    $cases = [
        'tanpa schema_version' => Arr::except($klasik, 'schema_version'),
        'schema_version 99' => [...$klasik, 'schema_version' => 99],
        'schema_version 0' => [...$klasik, 'schema_version' => 0],
        'schema_version string' => [...$klasik, 'schema_version' => '1'],
        'kunci puncak tak dikenal' => [...$klasik, 'script' => 'alert(1)'],
        'assets' => [...$klasik, 'assets' => ['css' => ['x.css']]],
        'tanpa name' => Arr::except($klasik, 'name'),
        'version bukan semver' => [...$klasik, 'version' => 'satu'],
        'min_core bukan semver' => [...$klasik, 'min_core' => 'baru'],
        'komponen header tak dikenal' => themeAppend($klasik, 'header.children', ['component' => 'iframe']),
        'komponen footer tak dikenal' => themeAppend($klasik, 'footer.children', ['component' => 'script']),
        'komponen footer di header' => themeAppend($klasik, 'header.children', ['component' => 'copyright']),
        'komponen header di footer' => themeAppend($klasik, 'footer.children', ['component' => 'nav_menu']),
        'komponen beranda di header' => themeAppend($klasik, 'header.children', ['component' => 'hero']),
        'tipe seksi beranda tak dikenal' => themeWith($klasik, 'home.sections.iframe', ['variant' => 'a']),
        'kunci node tak dikenal' => themeWith($klasik, 'home.sections.hero.html', '<b>x</b>'),
        'children pada bukan container' => themeWith($klasik, 'home.sections.hero.children', []),
        'varian tak dikenal' => themeWith($klasik, 'home.sections.hero.variant', 'tidak-ada'),
        'properti gaya tak dikenal color' => themeWith($klasik, 'home.sections.hero.style', ['color' => 'red']),
        'properti gaya tak dikenal background' => themeWith($klasik, 'home.sections.hero.style', ['background' => 'url(x)']),
        'responsif pada tone' => themeWith($klasik, 'home.sections.hero.style', ['tone' => ['base' => 'light', 'md' => 'dark']]),
        'responsif pada shadow' => themeWith($klasik, 'home.sections.hero.style', ['shadow' => ['base' => 'none', 'lg' => 'sm']]),
        'kunci breakpoint tak dikenal' => themeWith($klasik, 'home.sections.hero.style', ['padding' => ['base' => 'sm', 'xl' => 'lg']]),
        'columns di luar enum' => themeWith($klasik, 'home.sections.hero.style', ['columns' => 5]),
        'padding di luar enum' => themeWith($klasik, 'home.sections.hero.style', ['padding' => 'xl']),
        'slug berbeda dari direktori' => [...$klasik, 'slug' => 'lain'],
        'slug huruf besar' => [...$klasik, 'slug' => 'Klasik'],
        'slug terlalu pendek' => [...$klasik, 'slug' => 'k'],
        'slug terlalu panjang' => [...$klasik, 'slug' => str_repeat('a', 41)],
        'slug dengan path' => [...$klasik, 'slug' => '../klasik'],
        'lebih dari 150 node' => themeWith($klasik, 'header.children', array_fill(0, 151, ['component' => 'brand'])),
        'pohon bersarang 7 tingkat' => themeWith($klasik, 'header', array_reduce(range(1, 7), fn (array $inner): array => ['component' => 'container', 'children' => [$inner]], ['component' => 'brand'])),
    ];

    foreach ($badStyleValues as $index => $value) {
        $cases['nilai gaya tone #'.$index.' '.json_encode($value)] = themeWith($klasik, 'home.sections.hero.style', ['tone' => $value]);
        $cases['nilai gaya gap #'.$index.' '.json_encode($value)] = themeWith($klasik, 'header.style', ['gap' => $value]);
    }

    $accepted = [];

    foreach ($cases as $label => $manifest) {
        if (themeRejection($manifest) === null) {
            $accepted[] = $label;
        }
    }

    expect(themeRejection($klasik, 'beda-nama'))->not->toBeNull('slug klasik di direktori beda-nama')
        ->and($accepted)->toBe([], 'Manifest ini seharusnya ditolak: '.implode('; ', $accepted));
});

it('AC-1: rejects a broken or hostile file before trusting it', function () {
    $klasik = themeOfficialManifest('klasik');
    $valid = json_encode($klasik);
    $nested = str_repeat('{"a":', 12).'1'.str_repeat('}', 12);
    $cases = [
        'JSON terpotong' => '{"schema_version":1,',
        'berkas kosong' => '',
        'akar bukan objek (daftar)' => '[]',
        'akar null' => 'null',
        'akar string' => '"klasik"',
        'UTF-8 rusak di nilai' => preg_replace('/"name":"/', '"name":"Rusak '."\xB1\x31".'', $valid, 1),
        'depth JSON > 8' => $nested,
        'lebih dari 64 KB (JSON sah diberi spasi)' => $valid.str_repeat(' ', 70 * 1024),
    ];

    $accepted = array_keys(array_filter($cases, fn (string $raw): bool => themeRejection($raw) === null));

    expect($accepted)->toBe([], 'Berkas ini seharusnya ditolak: '.implode('; ', $accepted))
        ->and(fn () => app(ValidatePortalTheme::class)->handle(sys_get_temp_dir().'/tidak-ada-f102/klasik/theme.json'))->toThrow(InvalidArgumentException::class);
});

it('AC-9: ships only theme.json files, with no code, markup or assets, in the official theme directories', function () {
    $files = collect(File::allFiles(resource_path('themes')))
        ->reject(fn (SplFileInfo $file): bool => str_contains($file->getPathname(), '/zz-uji-'))
        ->map(fn (SplFileInfo $file): string => str_replace(resource_path('themes').'/', '', $file->getPathname()))
        ->sort()->values()->all();

    expect($files)->toBe(['centered/theme.json', 'default/theme.json', 'klasik/theme.json']);
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
