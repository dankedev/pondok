<?php

use App\Actions\Konten\SanitizeBlockText;

/*
| F045 AC-5. Kontrak: `SanitizeBlockText::handle(?string $html): string` mengembalikan HTML hanya dengan
| `<p>`, `<br>`, `<strong>`, `<a href>`. Href aman: `https?://` (tak peka huruf besar) atau path yang diawali
| tepat satu `/`. Tautan luar dipaksa `rel="noopener noreferrer"`. Murni di memori, jadi satu test dengan loop.
*/

function blkSanitize(?string $html): string
{
    return app(SanitizeBlockText::class)->handle($html);
}

it('AC-5: keeps only p, br, strong and a[href], dropping every other tag and attribute but keeping the text', function () {
    $clean = blkSanitize('<div class="x"><p style="color:red" onclick="a()" id="y">Satu<br>dua <strong class="k">tebal</strong> <em>miring</em></p><h1>Judul</h1><span>teks</span><img src="x" onerror="z()"><style>p{}</style><script>alert(1)</script></div>');

    expect($clean)->toContain('<p>Satu<br>dua <strong>tebal</strong>')
        ->toContain('Judul')->toContain('teks')->toContain('miring')
        ->not->toContain('<div')->not->toContain('<h1')->not->toContain('<span')->not->toContain('<em')->not->toContain('<img')
        ->not->toContain('<style')->not->toContain('<script')->not->toContain('alert(1)')
        ->not->toContain('class=')->not->toContain('style=')->not->toContain('onclick')->not->toContain('onerror')->not->toContain('id=');
});

it('AC-5: allows only safe link targets and forces rel on external links', function () {
    foreach (['https://baik.test/a', 'http://baik.test', 'HTTPS://BAIK.test/x', '  https://baik.test/trim  '] as $url) {
        $clean = blkSanitize('<a href="'.$url.'" target="_blank" rel="opener" onclick="x()">tautan</a>');

        expect($clean)->toContain('href="'.trim($url).'"')->toContain('rel="noopener noreferrer"')
            ->not->toContain('opener"')->not->toContain('onclick')->not->toContain('target=');
    }

    foreach (['/berita', '/halaman/profil?x=1'] as $path) {
        expect(blkSanitize('<a href="'.$path.'">dalam</a>'))->toContain('href="'.$path.'"');
    }

    foreach (['javascript:alert(1)', ' JavaScript:alert(1)', 'JAVASCRIPT:alert(1)', "java\tscript:alert(1)", 'jav&#x09;ascript:alert(1)', 'vbscript:x', 'data:text/html;base64,PHNjcmlwdD4=', '//evil.test/x', '/\\evil.test', '\\\\evil.test', 'mailto:a@b.test', 'ftp://x.test', 'berita', '#', ''] as $url) {
        $clean = blkSanitize('<p><a href="'.$url.'">tautan</a></p>');

        expect($clean)->toContain('tautan')
            ->not->toContain('javascript')->not->toContain('JavaScript')->not->toContain('JAVASCRIPT')->not->toContain('vbscript')
            ->not->toContain('data:')->not->toContain('evil.test')->not->toContain('mailto:')->not->toContain('ftp:');
        expect(preg_match('/href="(?!https?:\/\/|\/(?![\/\\\\]))/i', $clean))->toBe(0, "href berbahaya lolos: {$url} => {$clean}");
    }
});

it('AC-5: returns an empty string for empty input and does not break plain text', function () {
    expect(blkSanitize(null))->toBe('')->and(blkSanitize(''))->toBe('')->and(blkSanitize('   '))->toBe('')
        ->and(blkSanitize('Teks polos & aman'))->toContain('Teks polos')
        ->and(blkSanitize('<p>a &lt;script&gt; b</p>'))->not->toContain('<script>');
});
