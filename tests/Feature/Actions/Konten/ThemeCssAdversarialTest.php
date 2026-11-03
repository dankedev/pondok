<?php

use App\Actions\Konten\ValidatePortalTheme;
use App\Actions\Konten\ValidateThemeCss;

/*
| F105 VERIFY (adversarial). Celah yang dicurigai pada validator CSS dan kontras: penyamaran lewat komentar/unicode/CDO, selektor tanpa awalan di dalam
| @media atau @supports bersarang, `;` dan `}` di dalam string, serta kontras yang dilemahkan lewat definisi ulang setelah blok scope.
| Pola yang sudah tercakup ValidateThemeCssTest tidak diulang.
*/

function advCss(string $slug, string $extra): string
{
    return themePkgCss($slug, str_replace(['{SLUG}', '{S}'], [$slug, themePkgScope($slug)], $extra));
}

it('F105 VERIFY: rejects css disguised with comments, uppercase at-rules, CDO, unicode homoglyphs and selectors without the scope inside conditionals', function () {
    $slug = 'zz-adv';
    $svg = themePkgSvg();
    $cases = [
        'komentar di dalam nama fungsi url' => '{S}{background-image:u/**/rl("https://evil.example/x.png")}',
        '@IMPORT huruf besar' => '@IMPORT URL("https://evil.example/x.css");',
        'komentar di dalam nama at-rule' => '@im/**/port "https://evil.example/x.css";',
        'CDO di depan aturan' => '<!-- {S}{color:red}',
        'selektor tanpa awalan di dalam @media setelah koma' => '@media (min-width:1px){{S} a,div{color:red}}',
        '@media di dalam @supports' => '@supports (display:grid){@media (min-width:1px){{S} a{color:red}}}',
        'homoglyph omicron Yunani pada body' => "b\u{03BF}dy[data-pt-theme=\"{SLUG}\"] a{color:red}",
        'NBSP sebagai pemisah selektor' => "{S}\u{00A0}a{color:red}",
        'url() eksternal dipisah komentar sebelum kurung' => '{S}{background-image:url/**/("https://evil.example/x.png")}',
    ];

    // Kontrol: CSS sah yang sama tanpa fragmen lolos, dan fragmen sah (svg data-URI) tetap lolos.
    app(ValidateThemeCss::class)->handle(advCss($slug, '{S} [data-pt="hero"]{background-image:url("'.$svg.'")}'), $slug);

    foreach ($cases as $label => $fragment) {
        try {
            app(ValidateThemeCss::class)->handle(advCss($slug, $fragment), $slug);
        } catch (InvalidArgumentException) {
            continue;
        }

        test()->fail("{$label}: seharusnya ditolak.");
    }

    expect($cases)->not->toBeEmpty();
});

it('F105 VERIFY: keeps semicolons, braces and comment markers inside strings from breaking out of their declaration or selector', function () {
    $slug = 'zz-adv';
    $css = themePkgScope($slug).' [data-pt="hero"]::before{content:"};body{color:red}/*";color:blue}'."\n"
        .themePkgScope($slug).' [data-x="}{;,html"]{color:green}';

    $parsed = app(ValidateThemeCss::class)->handle($css, $slug);

    expect($parsed['rules'])->toHaveCount(2)
        ->and($parsed['rules'][0]['declarations'])->toHaveCount(2)
        ->and($parsed['rules'][0]['declarations'][0][0])->toBe('content')
        ->and($parsed['rules'][1]['selectors'])->toBe([themePkgScope($slug).' [data-x="}{;,html"]']);
});

it('F105 VERIFY: rejects a declared contrast pair whose colour is redefined after the scope block (component or media override)', function (string $override) {
    $pkg = themePkg();
    $css = $pkg['files']['theme.css']."\n".str_replace('{S}', themePkgScope($pkg['slug']), $override);
    file_put_contents("{$pkg['dir']}/theme.css", $css);

    $manifest = $pkg['manifest'];
    $manifest['assets']['css'] = ['path' => 'theme.css', 'sha256' => hash('sha256', $css), 'size' => strlen($css)];
    file_put_contents("{$pkg['dir']}/theme.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    app(ValidatePortalTheme::class)->handle("{$pkg['dir']}/theme.json");

    themePkgReject([...$pkg, 'manifest' => $manifest]);
})->with([
    'ditimpa di dalam komponen menjadi sama dengan teks' => ['{S} [data-pt="hero"]{--pt-bg:#1a2b1c}'],
    'ditimpa di dalam @media menjadi sama dengan teks' => ['@media (min-width:1px){{S}{--pt-bg:#1a2b1c}}'],
]);
