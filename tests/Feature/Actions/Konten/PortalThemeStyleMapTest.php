<?php

use App\Actions\Konten\BuildColorScale;
use App\Actions\Konten\GetPortalTheme;
use App\Actions\Konten\ThemeStyleMap;

/*
| F102 AC-3 (peta gaya) dan bagian ThemeStyleMap AC-9. `columns` = 1|2|3|4 (`1` untuk 360px). Kontrak yang diasumsikan:
|  - `ThemeStyleMap::classes(array $style): string` menerima node `style` tervalidasi (`properti => nilai` atau, hanya untuk
|    `padding` dan `columns`, `properti => ['base' => ..., 'md' => ..., 'lg' => ...]`) dan mengembalikan kelas Tailwind
|    dipisah spasi (kelas breakpoint berawalan `md:`/`lg:`). `columns` berupa bilangan bulat. Nilai `none` boleh menghasilkan teks kosong.
|  - Properti atau nilai tak dikenal, atau responsif pada properti selain `padding`/`columns`: `InvalidArgumentException`.
|  - Peta adalah array konstanta di `app/Actions/Konten/ThemeStyleMap.php`; hanya lookup.
| `tone: auto` dihitung renderer (selang-seling), jadi tidak dipetakan di sini. Keberadaan kelas di CSS hasil build dicek lewat
| `npm run build` saat rilis, bukan di test.
*/

const STYLE_ENUMS = [
    'tone' => ['light', 'muted', 'brand', 'dark'],
    'padding' => ['sm', 'md', 'lg'],
    'gap' => ['sm', 'md', 'lg'],
    'align' => ['left', 'center'],
    'width' => ['narrow', 'wide', 'full'],
    'radius' => ['none', 'md', 'lg'],
    'shadow' => ['none', 'sm'],
    'border' => ['none', 'top', 'bottom'],
    'columns' => [1, 2, 3, 4],
];

const STYLE_RESPONSIVE = ['padding', 'columns'];

/**
 * @return list<string>
 */
function styleTokens(array $style): array
{
    return array_values(array_filter(preg_split('/\s+/', trim(ThemeStyleMap::classes($style))) ?: []));
}

function styleThrows(array $style): bool
{
    try {
        ThemeStyleMap::classes($style);
    } catch (InvalidArgumentException) {
        return true;
    }

    return false;
}

/**
 * Semua kelas yang bisa dihasilkan peta: tiap nilai enum, dan varian responsif untuk padding dan columns.
 *
 * @return array<string, list<string>> properti => kelas
 */
function styleAllTokens(): array
{
    $all = [];

    foreach (STYLE_ENUMS as $property => $values) {
        foreach ($values as $value) {
            $all[$property] = [...($all[$property] ?? []), ...styleTokens([$property => $value])];

            if (in_array($property, STYLE_RESPONSIVE, true)) {
                $all[$property] = [...$all[$property], ...styleTokens([$property => ['base' => $value, 'md' => $value, 'lg' => $value]])];
            }
        }
    }

    return array_map(fn (array $tokens): array => array_values(array_unique($tokens)), $all);
}

/**
 * Isi sumber PHP tanpa komentar.
 */
function styleMapSource(): string
{
    $code = (string) file_get_contents(app_path('Actions/Konten/ThemeStyleMap.php'));

    return implode('', array_map(fn ($token): string => is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]) : $token, token_get_all($code)));
}

it('AC-9: maps every valid enum value to Tailwind classes, responsive only for columns and padding', function () {
    $all = styleAllTokens();

    foreach (STYLE_ENUMS as $property => $values) {
        foreach ($values as $value) {
            $tokens = styleTokens([$property => $value]);

            if (! in_array($value, ['none', 'left'], true)) {
                expect($tokens)->not->toBeEmpty("{$property}={$value} harus menghasilkan kelas");
            }

            expect(array_filter($tokens, fn (string $token): bool => str_contains($token, ':')))->toBe([], "{$property}={$value} tanpa breakpoint tidak boleh berawalan md:/lg:");
        }
    }

    foreach (STYLE_RESPONSIVE as $property) {
        $value = STYLE_ENUMS[$property][0];
        $other = STYLE_ENUMS[$property][1];
        $tokens = styleTokens([$property => ['base' => $value, 'md' => $other, 'lg' => STYLE_ENUMS[$property][2]]]);

        expect($tokens)->toContain(...styleTokens([$property => $value]))
            ->and(collect($tokens)->contains(fn (string $token): bool => str_starts_with($token, 'md:')))->toBeTrue("{$property} md")
            ->and(collect($tokens)->contains(fn (string $token): bool => str_starts_with($token, 'lg:')))->toBeTrue("{$property} lg");
    }

    $accepted = [];

    foreach (array_diff(array_keys(STYLE_ENUMS), STYLE_RESPONSIVE) as $property) {
        if (! styleThrows([$property => ['base' => STYLE_ENUMS[$property][0], 'md' => STYLE_ENUMS[$property][1]]])) {
            $accepted[] = $property;
        }
    }

    expect($accepted)->toBe([], 'Responsif seharusnya ditolak pada: '.implode(', ', $accepted))
        ->and($all)->toHaveKeys(array_keys(STYLE_ENUMS));
});

it('AC-3 and AC-9: throws for any key or value the map does not know instead of passing it through', function () {
    $accepted = [];

    foreach ([
        'properti tak dikenal' => ['color' => 'red'],
        'nilai tak dikenal' => ['padding' => 'xl'],
        'nilai css' => ['tone' => 'light; background:url(x)'],
        'nilai kelas bebas' => ['gap' => 'gap-96'],
        'columns di luar enum' => ['columns' => 7],
        'columns nol' => ['columns' => 0],
        'columns string tak sah' => ['columns' => 'banyak'],
        'breakpoint tak dikenal' => ['padding' => ['base' => 'sm', 'xl' => 'lg']],
    ] as $label => $style) {
        if (! styleThrows($style)) {
            $accepted[] = $label;
        }
    }

    $constants = collect((new ReflectionClass(ThemeStyleMap::class))->getConstants())->filter(fn (mixed $value): bool => is_array($value));

    expect($accepted)->toBe([], 'Seharusnya melempar InvalidArgumentException: '.implode(', ', $accepted))
        ->and($constants)->not->toBeEmpty('peta harus berupa array konstanta')
        ->and(ThemeStyleMap::classes(['tone' => 'dark']))->toBeString();
});

it('AC-9: keeps every class a whole literal token of the allowed Tailwind form, with no interpolation or concatenation', function () {
    $allowed = '/^(?:(?:sm|md|lg|xl|hover|focus|focus-visible):)?-?[a-z][a-z0-9]*(?:-[a-z0-9]+(?:\.[0-9]+)?)*(?:\/[0-9]+)?$/';
    $source = styleMapSource();
    $invalid = $missing = [];

    foreach (styleAllTokens() as $property => $tokens) {
        foreach ($tokens as $token) {
            if (preg_match($allowed, $token) !== 1) {
                $invalid[] = "{$property}: {$token}";
            }

            if (preg_match('/(?<![\w:\/.-])'.preg_quote($token, '/').'(?![\w:\/.-])/', $source) !== 1) {
                $missing[] = "{$property}: {$token}";
            }
        }
    }

    $constructed = [];
    $tokens = token_get_all('<?php '.$source);

    foreach ($tokens as $index => $token) {
        if (is_array($token) && in_array($token[0], [T_ENCAPSED_AND_WHITESPACE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
            $constructed[] = 'interpolasi: '.trim($token[1]);
        }

        if (is_array($token) && $token[0] === T_STRING && preg_match('/^(?:v?sprintf|strtr|str_replace|preg_replace)$/i', $token[1]) === 1) {
            $constructed[] = "fungsi {$token[1]}";
        }

        if ($token === '.') {
            foreach ([$tokens[$index - 1] ?? null, $tokens[$index - 2] ?? null, $tokens[$index + 1] ?? null, $tokens[$index + 2] ?? null] as $neighbour) {
                if (is_array($neighbour) && $neighbour[0] === T_CONSTANT_ENCAPSED_STRING && preg_match('/^[\'"][^\'"]*[:-][\'"]$|^[\'"][:-]/', $neighbour[1]) === 1) {
                    $constructed[] = "penyambungan kelas: {$neighbour[1]}";
                }
            }
        }
    }

    expect($invalid)->toBe([], 'Kelas di luar pola token yang diizinkan: '.implode(', ', $invalid))
        ->and($missing)->toBe([], 'Kelas tidak tertulis utuh di ThemeStyleMap.php: '.implode(', ', $missing))
        ->and($constructed)->toBe([], 'Kelas dirangkai saat runtime: '.implode(', ', $constructed));
});

it('AC-9: gives each tone only background and text pairs that keep 4.5:1 with the default and extreme F070 colours', function () {
    $allowedBackgrounds = ['light' => ['bg-white', 'bg-gray-50'], 'muted' => ['bg-gray-100'], 'brand' => ['bg-brand'], 'dark' => ['bg-primary-950']];
    $gray = [50 => '#f9fafb', 100 => '#f3f4f6', 200 => '#e5e7eb', 300 => '#d1d5db', 400 => '#9ca3af', 500 => '#6b7280', 600 => '#4b5563', 700 => '#374151', 800 => '#1f2937', 900 => '#111827'];

    // Skenario warna: tanpa tema (bawaan app.css) dan warna utama ekstrem yang lolos aturan F070.
    $scenarios = ['bawaan' => [BuildColorScale::DEFAULT_PRIMARY, ['brand' => BuildColorScale::DEFAULT_PRIMARY[900], 'on-brand' => '#ffffff', 'brand-text' => BuildColorScale::DEFAULT_PRIMARY[950]]]];

    foreach (['#000000', '#ffffff', '#ffff00', '#00ff00', '#ff0000', '#00ffff', '#808080', '#1f2a5e', '#facc15', '#15803d'] as $hex) {
        $brand = GetPortalTheme::brandTokens($hex);

        if ($brand !== null) {
            $scenarios[$hex] = [app(BuildColorScale::class)->handle($hex, 'primary'), ['brand' => $brand['brand'], 'on-brand' => $brand['on_brand'], 'brand-text' => $brand['brand_text']]];
        }
    }

    $resolve = function (string $name, array $primary, array $brand) use ($gray): ?string {
        return match (true) {
            $name === 'white' => '#ffffff',
            $name === 'black' => '#000000',
            isset($brand[$name]) => $brand[$name],
            preg_match('/^gray-(\d+)$/', $name, $m) === 1 => $gray[(int) $m[1]] ?? null,
            preg_match('/^primary-(\d+)$/', $name, $m) === 1 => $primary[(int) $m[1]] ?? null,
            preg_match('/^accent-(\d+)$/', $name, $m) === 1 => BuildColorScale::DEFAULT_ACCENT[(int) $m[1]] ?? null,
            default => null,
        };
    };

    expect(count($scenarios))->toBeGreaterThan(3);

    foreach ($allowedBackgrounds as $tone => $backgrounds) {
        $tokens = styleTokens(['tone' => $tone]);
        $bg = array_values(array_filter($tokens, fn (string $token): bool => str_starts_with($token, 'bg-')));
        $text = array_values(array_filter($tokens, fn (string $token): bool => preg_match('/^text-(?:white|black|brand|on-brand|brand-text|(?:gray|primary|accent)-\d+)$/', $token) === 1));

        expect($bg)->not->toBeEmpty("tone {$tone} tanpa latar")
            ->and(array_diff($bg, $backgrounds))->toBe([], "tone {$tone}: latar di luar daftar terjamin")
            ->and($text)->not->toBeEmpty("tone {$tone} tanpa warna teks");

        foreach ($scenarios as $scenario => [$primary, $brand]) {
            foreach ($bg as $background) {
                foreach ($text as $foreground) {
                    $back = $resolve(substr($background, 3), $primary, $brand);
                    $front = $resolve(substr($foreground, 5), $primary, $brand);

                    expect($back)->not->toBeNull("{$background} tidak dikenali")->and($front)->not->toBeNull("{$foreground} tidak dikenali");
                    expect(BuildColorScale::contrast($back, $front))->toBeGreaterThanOrEqual(4.5, "tone {$tone} {$background}/{$foreground} pada {$scenario}");
                }
            }
        }
    }
});

it('AC-9: keeps each property on its own utility prefix and never emits focus, ring or outline classes', function () {
    $prefixOf = function (string $token): string {
        $base = preg_replace('/^(?:[a-z-]+:)+/', '', $token);

        return match (true) {
            str_starts_with($base, 'bg-') => 'bg',
            preg_match('/^text-(?:left|center|right|justify|start|end)$/', $base) === 1 => 'text-align',
            str_starts_with($base, 'text-') => 'text-color',
            default => preg_replace('/-[a-z0-9.\/]+$/', '', $base),
        };
    };

    $owners = [];

    foreach (styleAllTokens() as $property => $tokens) {
        foreach ($tokens as $token) {
            expect($token)->not->toMatch('/focus|ring|outline/i', "{$property}: kelas fokus tak boleh bisa diatur gaya");
            $owners[$prefixOf($token)][$property] = true;
        }
    }

    $shared = array_keys(array_filter($owners, fn (array $properties): bool => count($properties) > 1));

    expect($owners)->not->toBeEmpty()
        ->and($shared)->toBe([], 'Awalan utilitas dipakai lebih dari satu properti: '.implode(', ', $shared));
});
