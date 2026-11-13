<?php

/*
| F105 AC-3, AC-4, AC-6. Validator CSS (lewat `ValidateThemePackage`, lihat tests/Pest.php `themePkg`) menolak SETIAP pola di dataset `forbiddenCss`:
| satu baris = satu test, fragmen ditambahkan ke CSS sah (`themePkgCss`) sehingga satu-satunya perbedaan adalah fragmen itu. Placeholder `{S}`,
| `{SLUG}`, `{SLUG_UPPER}` diisi per test. Hasil harus `InvalidArgumentException` (bukan lolos, bukan exception lain).
*/

function cssFragment(string $fragment): string
{
    $fragment = preg_replace_callback('/@@REP:(.):(\d+)@@/s', fn (array $m) => str_repeat($m[1], (int) $m[2]), $fragment);
    $fragment = str_replace(['@@REPQ@@', '@@NUL@@', '@@BADUTF8@@'], [str_repeat('"\'"', 20000), "\0", "\xC3\x28"], $fragment);
    $fragment = str_replace('@@REPEAT2001@@', str_repeat('{S}{color:red}', 2001), $fragment);
    $fragment = str_replace('@@NEST3000@@', str_repeat('{S} a{', 3000), $fragment);
    $fragment = str_replace('@@SIXURI@@', implode("\n", array_map(fn (int $i) => '{S} [data-pt="hero"]{background-image:url("'.themePkgSvgWith(str_repeat('a', 3800)).'")}', range(1, 6))), $fragment);
    $fragment = str_replace('@@BIGURI@@', themePkgSvgWith(str_repeat('a', 4500)), $fragment);

    return (string) preg_replace_callback('/@@(.*?)@@/s', fn (array $m) => themePkgSvgWith($m[1]), $fragment);
}

dataset('forbiddenCss', [
    'expression()' => '{S}{width:expression(alert(1))}',
    'EXPRESSION() huruf besar' => '{S}{width:EXPRESSION(alert(1))}',
    'behavior' => '{S}{behavior:url(x.htc)}',
    '-moz-binding' => '{S}{-moz-binding:url("x.xml#y")}',
    'javascript: scheme' => '{S}{background:url("javascript:alert(1)")}',
    'JaVaScRiPt: campuran huruf' => '{S}{background:url("JaVaScRiPt:alert(1)")}',
    'vbscript: scheme' => '{S}{background:url("vbscript:msgbox(1)")}',
    'image-set()' => '{S}{background-image:image-set("a.png" 1x)}',
    '-webkit-image-set()' => '{S}{background-image:-webkit-image-set("a.png" 1x)}',
    'src()' => '{S}{background-image:src("a.png")}',
    'element()' => '{S}{background:element(#a)}',
    'cursor: url()' => '{S}{cursor:url(a.cur),auto}',
    'list-style-image' => '{S} ul{list-style-image:url(a.png)}',
    'content: url()' => '{S} [data-pt="hero"]::before{content:url(a.png)}',
    '!important' => '{S}{color:red!important}',
    '! important dengan spasi' => '{S}{color:red ! important}',
    '!IMPORTANT huruf besar' => '{S}{color:red!IMPORTANT}',
    '@import' => '@import "https://evil.example/x.css";',
    '@import url()' => '@import url("x.css");',
    '@namespace' => '@namespace svg url(http://www.w3.org/2000/svg);',
    '@page' => '@page{margin:0}',
    '@layer' => '@layer base{{S} a{color:red}}',
    '@container' => '@container (min-width:1px){{S} a{color:red}}',
    '@charset' => '@charset "utf-8";',
    '@-webkit-keyframes' => '@-webkit-keyframes pt-a{from{opacity:0}to{opacity:1}}',
    '@property' => '@property --pt-x{syntax:"<color>";inherits:false;initial-value:red}',
    'backslash di nilai' => '{S}{color:\\72 ed}',
    'backslash di nama fungsi' => '{S}{width:\\63 alc(1px)}',
    'backslash di dalam string' => '{S} [data-x="a\\"b"]{color:red}',
    'backslash di content' => '{S} [data-pt="quote"]::before{content:"\\201C"}',
    'backslash di selektor' => '{S} [data-pt="hero"] a\\:b{color:red}',
    'backslash di url()' => '{S}{background:url("data:image/svg+xml,%3Csvg%3E\\3C/svg%3E")}',
    'url() di custom property' => '{S}{--pt-x:url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\'%3E%3C/svg%3E")}',
    'url() di prelude @media' => '@media (min-width:1px) and url(x){{S} a{color:red}}',
    'url() di prelude @supports' => '@supports (background:url(x)){{S} a{color:red}}',
    'url() di properti color' => '{S}{color:url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\'%3E%3C/svg%3E")}',
    'url() di properti filter' => '{S}{filter:url(#f)}',
    'url() di properti border' => '{S}{border:1px solid url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\'%3E%3C/svg%3E")}',
    'url() di fallback var()' => '{S}{background:var(--a,url("https://evil.example/x.png"))}',
    'url() https eksternal' => '{S}{background-image:url("https://evil.example/x.png")}',
    'url() http eksternal' => '{S}{background-image:url(http://evil.example/x.png)}',
    'url() protocol-relative' => '{S}{background-image:url(//evil.example/x.png)}',
    'url() path relatif ke berkas' => '{S}{background-image:url("a.png")}',
    'url() path absolut' => '{S}{background-image:url("/x.png")}',
    'url() data png base64' => '{S}{background-image:url("data:image/png;base64,AAAA")}',
    'url() data html' => '{S}{background-image:url("data:text/html,%3Cscript%3E")}',
    'url() svg base64' => '{S}{background-image:url("data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=")}',
    'url() svg mentah tanpa enkode persen' => '{S}{background-image:url("data:image/svg+xml,<svg xmlns=\'http://www.w3.org/2000/svg\'></svg>")}',
    'url() mask dari https' => '{S}{mask-image:url("https://evil.example/m.svg")}',
    'url() border-image dari https' => '{S}{border-image-source:url("https://evil.example/b.png")}',
    'url() tanpa kutip dengan spasi dan tanda kurung' => '{S}{background-image:url( https://evil.example/x.png )}',
    'nesting dengan &' => '{S}{color:red;& a{color:blue}}',
    'nesting & di awal' => '&{color:red}',
    'blok di dalam blok' => '{S}{color:red;[data-pt="hero"]{color:blue}}',
    'blok di dalam blok aturan' => '{S} a{&:hover{color:red}}',
    '@media di dalam @media' => '@media (min-width:1px){@media (min-width:2px){{S} a{color:red}}}',
    'blok di dalam aturan di dalam @media' => '@media (min-width:1px){{S} a{color:red;b{color:blue}}}',
    'koma setelah :is(a,b) menyelundupkan body' => '{S} :is(a,b),body{color:red}',
    'koma setelah string atribut menyelundupkan html' => '{S} [data-x="a,b"],html{color:red}',
    'koma menyelundupkan selektor tanpa awalan' => '{S} a,div{color:red}',
    'koma menyelundupkan :root' => '{S} a,:root{--pt-x:1}',
    'koma di dalam :not() lalu body' => '{S} a:not([data-x="1,2"]),body a{color:red}',
    ':root telanjang' => ':root{--pt-x:1}',
    'html telanjang' => 'html{color:red}',
    '* telanjang' => '*{margin:0}',
    'body telanjang' => 'body{color:red}',
    'body a tanpa atribut scope' => 'body a{color:red}',
    'tag a saja' => 'a{color:red}',
    'atribut tanpa scope' => '[data-pt="hero"]{color:red}',
    'pseudo tanpa scope' => '::selection{color:red}',
    'html di depan scope' => 'html {S} a{color:red}',
    'div di depan scope' => 'div {S} a{color:red}',
    'scope di tengah selektor' => 'a {S}{color:red}',
    'scope hanya di dalam :is()' => ':is({S}) a{color:red}',
    'scope hanya di dalam :where()' => ':where({S}) a{color:red}',
    'BODY huruf besar' => 'BODY[data-pt-theme="{SLUG}"] a{color:red}',
    'slug huruf besar' => 'body[data-pt-theme="{SLUG_UPPER}"] a{color:red}',
    'slug diperpanjang (substring)' => 'body[data-pt-theme="{SLUG}-lain"] a{color:red}',
    'slug tema lain' => 'body[data-pt-theme="tema-lain"] a{color:red}',
    'operator ^= pada atribut scope' => 'body[data-pt-theme^="{SLUG}"] a{color:red}',
    'operator *= pada atribut scope' => 'body[data-pt-theme*="{SLUG}"] a{color:red}',
    'spasi antara body dan atribut' => 'body [data-pt-theme="{SLUG}"] a{color:red}',
    'atribut scope tanpa kutip' => 'body[data-pt-theme={SLUG}] a{color:red}',
    'atribut scope dengan kutip tunggal' => 'body[data-pt-theme=\'{SLUG}\'] a{color:red}',
    'selektor kelas' => '{S} .hero{color:red}',
    'selektor id' => '{S} #main{color:red}',
    'kelas menempel pada atribut' => '{S} [data-pt="hero"].extra{color:red}',
    'id menempel pada tag' => '{S} a#x{color:red}',
    'kelas di dalam :not()' => '{S} a:not(.x){color:red}',
    'kelas di dalam :is()' => '{S} :is(.a,.b){color:red}',
    'id di dalam :not()' => '{S} a:not(#x){color:red}',
    'atribut [class]' => '{S} [class*="hero"]{color:red}',
    'atribut [id]' => '{S} [id="main"]{color:red}',
    'atribut [href]' => '{S} [href^="http"]{color:red}',
    'atribut [style]' => '{S} [style]{color:red}',
    '@keyframes tanpa awalan pt-' => '@keyframes spin{from{opacity:0}to{opacity:1}}',
    '@keyframes dengan awalan mirip' => '@keyframes ptx-spin{from{opacity:0}to{opacity:1}}',
    '@keyframes PT- huruf besar' => '@keyframes PT-spin{from{opacity:0}to{opacity:1}}',
    'fungsi paint()' => '{S}{background:paint(checker)}',
    'fungsi tak dikenal evil()' => '{S}{width:evil(1px)}',
    'fungsi tak dikenal di dalam calc()' => '{S}{width:calc(1px + evil(2px))}',
    'fungsi cross-fade()' => '{S}{background-image:cross-fade(url(a.png),url(b.png),50%)}',
    'attr() dengan tipe url' => '{S}{background:attr(data-x url)}',
    'SVG berbahaya: <script>' => '{S} [data-pt="hero"]{background-image:url("@@<script>alert(1)</script>@@")}',
    'SVG berbahaya: <SCRIPT> huruf besar' => '{S} [data-pt="hero"]{background-image:url("@@<SCRIPT>alert(1)</SCRIPT>@@")}',
    'SVG berbahaya: <foreignObject>' => '{S} [data-pt="hero"]{background-image:url("@@<foreignObject><div/></foreignObject>@@")}',
    'SVG berbahaya: <image>' => '{S} [data-pt="hero"]{background-image:url("@@<image width="1" height="1"/>@@")}',
    'SVG berbahaya: <use>' => '{S} [data-pt="hero"]{background-image:url("@@<use width="1" height="1"/>@@")}',
    'SVG berbahaya: <style>' => '{S} [data-pt="hero"]{background-image:url("@@<style>*{fill:red}</style>@@")}',
    'SVG berbahaya: atribut href' => '{S} [data-pt="hero"]{background-image:url("@@<a href="https://evil.example"><rect/></a>@@")}',
    'SVG berbahaya: url() non-fragmen' => '{S} [data-pt="hero"]{background-image:url("@@<rect fill="url(https://evil.example/a)"/>@@")}',
    'SVG berbahaya: handler onload=' => '{S} [data-pt="hero"]{background-image:url("@@<rect onload="x()"/>@@")}',
    'SVG berbahaya: handler onclick= kutip tunggal' => '{S} [data-pt="hero"]{background-image:url("@@<rect onclick=\'x()\'/>@@")}',
    'CSS di atas 128 KB' => '/*@@REP:x:131100@@*/',
    'lebih dari 2000 aturan' => '@@REPEAT2001@@',
    'satu URI di atas 4 KB' => '{S} [data-pt="hero"]{background-image:url("@@BIGURI@@")}',
    'URI total di atas 16 KB' => '@@SIXURI@@',
    'UTF-8 tidak sah di komentar' => '/* @@BADUTF8@@ */',
    'NUL di akhir' => '{S}{color:red}@@NUL@@',
    'kurung kurawal tidak seimbang (kurang)' => '{S}{color:red',
    'kurung kurawal tidak seimbang (lebih)' => '{S}{color:red}}',
    'kurung kurawal @media tidak seimbang' => '@media (min-width:1px){{S}{color:red}',
    'komentar tidak ditutup' => '/* tak ditutup {S}{color:red}',
    'string tidak ditutup' => '{S} [data-x="abc]{color:red}',
    'kurung biasa tidak seimbang' => '{S}{width:calc(1px + (2px)}',
    'string tak tertutup sangat panjang' => '{S} [data-x="@@REP:a:120000@@',
    'komentar tak tertutup sangat panjang' => '/*@@REP:*:120000@@',
    'puluhan ribu kurung buka' => '{S}{width:calc@@REP:(:30000@@',
    'puluhan ribu kurung kurawal buka' => '@@REP:{:50000@@',
    'blok bersarang tak tertutup 3000 tingkat' => '@@NEST3000@@',
    'banyak tanda kutip bergantian' => '{S}{content:@@REPQ@@}',
]);
it('F105 AC-3: rejects every forbidden css pattern, failing closed', function (string $fragment) {
    $pkg = themePkg(['extra' => cssFragment($fragment)]);

    if (str_contains($fragment, '@@REP:x:131100@@')) {
        // Batas 128 KB sudah ditahan skema (size manifest) atau validator CSS; keduanya penolakan yang sah.
        themePkgValidate(themePkg());
        themeAssertRejected(function () use ($pkg) {
            themePkgValidate($pkg);
        });

        return;
    }

    themePkgReject($pkg);
})->with('forbiddenCss');

it('F105 AC-4: accepts representative mockup css', function () {
    $pkg = themePkg();

    themePkgValidate($pkg);

    expect($pkg['files']['theme.css'])->toContain('clip-path:polygon')->toContain('mask-image')->toContain('@keyframes pt-rise')
        ->toContain('prefers-reduced-motion:no-preference')->toContain('@font-face')->toContain('data:image/svg+xml,');
});

it('F105 AC-4: accepts safe variations the validator must not over-block', function () {
    $fragments = [
        'fragmen url() di dalam SVG' => '{S} [data-pt="hero"]{background-image:url("'.themePkgSvgWith('<defs><linearGradient id="g"/></defs><rect fill="url(#g)"/>').'")}',
        'border-image-source SVG' => '{S} [data-pt="quote"]{border-image-source:url("'.themePkgSvg().'");border-image-slice:1}',
        'koma di dalam :not() dan string atribut' => '{S} a:not([data-x="1,2"]):not(:hover),{S} [data-pt="hero"] [data-y="a,b"]{color:var(--pt-accent)}',
        'selektor atribut aria dan lang' => '{S} [aria-expanded="true"] [lang="id"]{color:var(--pt-accent)}',
        'pseudo-element' => '{S} [data-pt="hero"]::after{content:"";display:block}',
        '@keyframes persen' => '@keyframes pt-geser{0%{transform:translateX(0)}100%{transform:translateX(10px)}}',
        'bayangan keras dan radius elips' => '{S} [data-pt="cta"]{border-radius:60% 40% 55% 45% / 50% 60% 40% 50%;box-shadow:4px 4px 0 #000}',
        'warna rgba dan gradient' => '{S} [data-pt="hero"]{background:linear-gradient(180deg,rgba(0,0,0,.4),transparent)}',
        'kait F108 contact dan mark' => '{S} [data-pt="page"] [data-pt-part="contact"],{S} [data-pt="quote"] [data-pt-part="mark"]{border-color:var(--pt-accent)}',
        'komentar berisi kata terlarang' => '/* expression( javascript: url(x) @import */',
    ];

    foreach ($fragments as $label => $fragment) {
        try {
            themePkgValidate(themePkg(['extra' => cssFragment($fragment)]));
        } catch (InvalidArgumentException $e) {
            test()->fail("{$label}: seharusnya lolos, ditolak dengan '{$e->getMessage()}'");
        }
    }

    expect($fragments)->not->toBeEmpty();
});

dataset('motionOutsideNoPreference', [
    'animation di luar media' => '{S} a{animation:pt-rise 1s}',
    'animation-name di luar media' => '{S} a{animation-name:pt-rise}',
    'animation-duration di luar media' => '{S} a{animation-duration:1s}',
    'ANIMATION huruf besar' => '{S} a{ANIMATION:pt-rise 1s}',
    'transition di luar media' => '{S} a{transition:color .2s}',
    'transition-duration di luar media' => '{S} a{transition-duration:.3s}',
    'transition-property di luar media' => '{S} a{transition-property:color}',
    'scroll-behavior smooth di luar media' => '{S}{scroll-behavior:smooth}',
    'animation di prefers-reduced-motion: reduce' => '@media (prefers-reduced-motion:reduce){{S} a{animation:pt-rise 1s}}',
    'transition di media lain' => '@media (min-width:1px){{S} a{transition:color .2s}}',
    'animation di @supports' => '@supports (display:grid){{S} a{animation:pt-rise 1s}}',
    'scroll-behavior di media lain' => '@media (min-width:1px){{S}{scroll-behavior:smooth}}',
]);

it('F105 AC-6: rejects motion that is active outside prefers-reduced-motion: no-preference', function (string $fragment) {
    themePkgReject(themePkg(['extra' => $fragment]));
})->with('motionOutsideNoPreference');

it('F105 AC-6: accepts motion inside no-preference and the neutral values none, 0s and auto outside it', function () {
    $fragments = [
        'dalam no-preference' => '@media (prefers-reduced-motion:no-preference){{S}{scroll-behavior:smooth}{S} a{transition:transform .3s ease}}',
        'nilai netral di luar' => '{S} a{animation:none;transition:none;transition-duration:0s;animation-duration:0s;scroll-behavior:auto}',
    ];

    foreach ($fragments as $label => $fragment) {
        try {
            themePkgValidate(themePkg(['extra' => $fragment]));
        } catch (InvalidArgumentException $e) {
            test()->fail("{$label}: seharusnya lolos, ditolak dengan '{$e->getMessage()}'");
        }
    }

    expect($fragments)->toHaveCount(2);
});
