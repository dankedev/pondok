<?php

namespace App\Actions\Konten;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Validator `theme.css` (F105), tulis sendiri tanpa dependensi, berbasis ALLOWLIST dan FAIL-CLOSED: galat tak terduga apa pun
 * (termasuk galat PCRE) = ditolak. Hanya tema resmi bertanda tangan yang sampai ke sini, tetapi CSS tetap dianggap tak tepercaya.
 *
 * Aturan (satu per satu, lihat rencana F105 butir 9): tanpa backslash dan NUL, UTF-8 sah, <= 128 KB, <= 2000 aturan, tanpa nesting;
 * tiap selektor berawalan token kanonik `body[data-pt-theme="<slug>"]` dan hanya memuat tipe, atribut `data-*`/`aria-*`/`lang`/`dir`,
 * pseudo, dan kombinator (tanpa kelas/id); at-rule hanya `@media`/`@supports` (aturan datar), `@keyframes pt-*`, `@font-face`; fungsi
 * hanya dari daftar izin; `url()` hanya untuk `@font-face src` ke font tema dan `data:image/svg+xml,` terenkode-persen (<= 4 KB per URI,
 * <= 16 KB total, tanpa unsur SVG berbahaya) pada `background*`/`mask*`/`border-image-source`; tanpa `!important`.
 *
 * Pemeriksaan yang butuh manifest (font, kontras, gerak) dikerjakan `ValidateThemePackage` dari hasil ini.
 */
class ValidateThemeCss
{
    public const MAX_BYTES = 131072;

    public const MAX_RULES = 2000;

    public const MAX_URI_BYTES = 4096;

    public const MAX_URI_TOTAL_BYTES = 16384;

    /** Satu nilai deklarasi (data URI 4 KB termasuk); nilai sebesar ini pun tak pernah sah dan menahan masukan sengaja besar. */
    public const MAX_VALUE_BYTES = 8192;

    private const MAX_SELECTOR_DEPTH = 6;

    /** Fungsi yang boleh muncul di nilai; `url` dan `attr` diperiksa khusus. */
    private const FUNCTIONS = [
        'var', 'calc', 'min', 'max', 'clamp',
        'rgb', 'rgba', 'hsl', 'hsla', 'hwb', 'lab', 'lch', 'oklab', 'oklch', 'color', 'color-mix',
        'linear-gradient', 'repeating-linear-gradient', 'radial-gradient', 'repeating-radial-gradient', 'conic-gradient', 'repeating-conic-gradient',
        'translate', 'translatex', 'translatey', 'translatez', 'translate3d', 'scale', 'scalex', 'scaley', 'scale3d',
        'rotate', 'rotatex', 'rotatey', 'rotatez', 'rotate3d', 'skew', 'skewx', 'skewy', 'matrix', 'matrix3d', 'perspective',
        'cubic-bezier', 'steps', 'linear', 'circle', 'ellipse', 'inset', 'polygon', 'counter', 'counters',
        'blur', 'brightness', 'contrast', 'drop-shadow', 'grayscale', 'hue-rotate', 'invert', 'opacity', 'saturate', 'sepia',
        'repeat', 'minmax', 'fit-content', 'format', 'url', 'attr',
    ];

    private const FONT_FACE_PROPERTIES = ['font-family', 'src', 'font-weight', 'font-style', 'font-display', 'font-stretch', 'unicode-range', 'size-adjust', 'ascent-override', 'descent-override', 'line-gap-override'];

    private const DENIED_PROPERTIES = ['behavior', '-moz-binding'];

    private const URL_PROPERTIES = '/^(?:(?:background|mask|-webkit-mask)(?:-[a-z-]+)?|border-image-source)\z/';

    private const FONT_URL = '/^fonts\/[a-z0-9][a-z0-9-]{0,40}\.woff2\z/';

    private string $text = '';

    private int $pos = 0;

    private int $length = 0;

    private int $ruleCount = 0;

    private int $uriBytes = 0;

    private string $scope = '';

    /** @var list<array{selectors: list<string>, top: bool, motion: bool, declarations: list<array{0: string, 1: string}>}> */
    private array $rules = [];

    /** @var list<list<array{0: string, 1: string}>> */
    private array $fontFaces = [];

    /**
     * Hasil: `rules` (selektor, `top` = di luar @media/@supports, `motion` = di dalam @media (prefers-reduced-motion: no-preference),
     * pasangan [properti, nilai]) dan `font_faces` (pasangan [properti, nilai] tiap @font-face).
     *
     * @return array{rules: list<array{selectors: list<string>, top: bool, motion: bool, declarations: list<array{0: string, 1: string}>}>, font_faces: list<list<array{0: string, 1: string}>>}
     *
     * @throws InvalidArgumentException
     */
    public function handle(string $css, string $slug): array
    {
        $this->ruleCount = 0;
        $this->uriBytes = 0;
        $this->rules = [];
        $this->fontFaces = [];
        $this->scope = 'body[data-pt-theme="'.$slug.'"]';

        try {
            $this->parse($css);
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->fail('css_internal');
        }

        return ['rules' => $this->rules, 'font_faces' => $this->fontFaces];
    }

    private function parse(string $css): void
    {
        if (strlen($css) > self::MAX_BYTES) {
            $this->fail('css_too_large', ['max' => self::MAX_BYTES / 1024]);
        }

        if (! mb_check_encoding($css, 'UTF-8')) {
            $this->fail('css_invalid_utf8');
        }

        if (str_contains($css, '\\') || str_contains($css, "\0")) {
            $this->fail('css_forbidden_char');
        }

        $this->text = $this->stripComments($css);
        $this->length = strlen($this->text);
        $this->pos = 0;

        while (true) {
            $this->skipWhitespace();

            if ($this->pos >= $this->length) {
                return;
            }

            match ($this->text[$this->pos]) {
                '}' => $this->fail('css_unbalanced'),
                '@' => $this->parseAtRule(),
                default => $this->parseRule(null),
            };
        }
    }

    /**
     * Ganti komentar dengan satu spasi; string harus tertutup di baris yang sama dan komentar harus ditutup.
     */
    private function stripComments(string $css): string
    {
        $out = '';
        $i = 0;
        $n = strlen($css);

        while ($i < $n) {
            $run = strcspn($css, "\"'/", $i);
            $out .= substr($css, $i, $run);
            $i += $run;

            if ($i >= $n) {
                break;
            }

            $char = $css[$i];

            if ($char === '/') {
                if (($css[$i + 1] ?? '') === '*') {
                    $end = strpos($css, '*/', $i + 2);

                    if ($end === false) {
                        $this->fail('css_unclosed');
                    }

                    $out .= ' ';
                    $i = $end + 2;
                } else {
                    $out .= '/';
                    $i++;
                }

                continue;
            }

            $end = strpos($css, $char, $i + 1);

            if ($end === false || strcspn($css, "\n\r\f", $i + 1, $end - $i - 1) !== $end - $i - 1) {
                $this->fail('css_unclosed');
            }

            $out .= substr($css, $i, $end - $i + 1);
            $i = $end + 1;
        }

        return $out;
    }

    private function parseAtRule(): void
    {
        $this->pos++;
        $name = strtolower(substr($this->text, $this->pos, strspn($this->text, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_', $this->pos)));
        $this->pos += strlen($name);

        if (! in_array($name, ['media', 'supports', 'keyframes', 'font-face'], true)) {
            $this->fail('css_at_rule', ['name' => '@'.Str::limit($name, 30)]);
        }

        $prelude = $this->readPrelude();
        $this->countRule();

        match ($name) {
            'media', 'supports' => $this->parseConditionalRules($name, $prelude),
            'keyframes' => $this->parseKeyframes($prelude),
            'font-face' => $this->parseFontFace($prelude),
        };
    }

    private function parseConditionalRules(string $name, string $prelude): void
    {
        $lower = strtolower($prelude);

        if ($lower === '' || str_contains($lower, 'url(') || preg_match('/^[a-z0-9\s():.,<>=\/%+*-]*\z/', $lower) !== 1) {
            $this->fail('css_prelude', ['prelude' => Str::limit($prelude, 60)]);
        }

        $context = $name === 'media' ? preg_replace('/\s*:\s*/', ':', (string) preg_replace('/\s+/', ' ', $lower)) : '@supports';

        // Konteks null berarti "di luar kondisi"; galat PCRE tidak boleh berakhir di sana.
        if (! is_string($context)) {
            $this->fail('css_internal');
        }

        while (true) {
            $this->skipWhitespace();

            if ($this->pos >= $this->length) {
                $this->fail('css_unbalanced');
            }

            if ($this->text[$this->pos] === '}') {
                $this->pos++;

                return;
            }

            if ($this->text[$this->pos] === '@') {
                $this->fail('css_at_rule', ['name' => '@(bersarang)']);
            }

            $this->parseRule($context);
        }
    }

    private function parseKeyframes(string $name): void
    {
        if (preg_match('/^pt-[a-z0-9-]+\z/', $name) !== 1) {
            $this->fail('css_keyframes');
        }

        $frame = '(?:from|to|\d+(?:\.\d+)?%)';

        while (true) {
            $this->skipWhitespace();

            if ($this->pos >= $this->length) {
                $this->fail('css_unbalanced');
            }

            if ($this->text[$this->pos] === '}') {
                $this->pos++;

                return;
            }

            $selector = $this->readPrelude();

            if (preg_match("/^{$frame}(?:\\s*,\\s*{$frame})*\\z/i", $selector) !== 1) {
                $this->fail('css_keyframes');
            }

            $this->countRule();
            $this->declarations($this->readBlock(), 'rule');
        }
    }

    private function parseFontFace(string $prelude): void
    {
        if ($prelude !== '') {
            $this->fail('css_at_rule', ['name' => '@font-face']);
        }

        $this->fontFaces[] = $this->declarations($this->readBlock(), 'font-face');
    }

    /**
     * @param  ?string  $media  null di luar kondisi; selain itu prelude @media yang dinormalkan (atau `@supports`)
     */
    private function parseRule(?string $media): void
    {
        $this->countRule();

        $selectors = $this->selectors($this->readPrelude());

        $this->rules[] = [
            'selectors' => $selectors,
            'top' => $media === null,
            'motion' => $media !== null && $this->isReducedMotionMedia($media),
            'declarations' => $this->declarations($this->readBlock(), 'rule'),
        ];
    }

    /**
     * Hanya `(prefers-reduced-motion:no-preference)` yang dikonjungsikan dengan `and`; tanpa koma, `or`, atau `not`.
     */
    private function isReducedMotionMedia(string $media): bool
    {
        if (str_contains($media, ',') || str_contains($media, ' or ') || str_contains($media, 'not ')) {
            return false;
        }

        return in_array('(prefers-reduced-motion:no-preference)', explode(' and ', $media), true);
    }

    private function countRule(): void
    {
        if (++$this->ruleCount > self::MAX_RULES) {
            $this->fail('css_too_many_rules', ['max' => self::MAX_RULES]);
        }
    }

    private function skipWhitespace(): void
    {
        $this->pos += strspn($this->text, " \t\r\n\f", $this->pos);
    }

    /**
     * Baca sampai `{` tingkat atas (string dilewati) dan lewati `{`; `;`, `}`, kurung tak seimbang, atau akhir berkas = ditolak.
     */
    private function readPrelude(): string
    {
        $start = $this->pos;
        $depth = 0;

        while ($this->pos < $this->length) {
            $this->pos += strcspn($this->text, "\"'()[]{};", $this->pos);

            if ($this->pos >= $this->length) {
                break;
            }

            $char = $this->text[$this->pos];

            if ($char === '"' || $char === "'") {
                $this->pos = strpos($this->text, $char, $this->pos + 1) + 1;

                continue;
            }

            if ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                if (--$depth < 0) {
                    $this->fail('css_unbalanced');
                }
            } elseif ($char === '{' && $depth === 0) {
                $prelude = trim(substr($this->text, $start, $this->pos - $start));
                $this->pos++;

                return $prelude;
            } else {
                $this->fail('css_unbalanced');
            }

            $this->pos++;
        }

        $this->fail('css_unbalanced');
    }

    /**
     * Isi blok deklarasi sampai `}` yang cocok; `{` di dalamnya = nesting = ditolak.
     */
    private function readBlock(): string
    {
        $start = $this->pos;
        $depth = 0;

        while ($this->pos < $this->length) {
            $this->pos += strcspn($this->text, "\"'()[]{}", $this->pos);

            if ($this->pos >= $this->length) {
                break;
            }

            $char = $this->text[$this->pos];

            if ($char === '"' || $char === "'") {
                $this->pos = strpos($this->text, $char, $this->pos + 1) + 1;

                continue;
            }

            if ($char === '{') {
                $this->fail('css_nesting');
            }

            if ($char === '}') {
                if ($depth !== 0) {
                    $this->fail('css_unbalanced');
                }

                $body = substr($this->text, $start, $this->pos - $start);
                $this->pos++;

                return $body;
            }

            $depth += $char === '(' || $char === '[' ? 1 : -1;

            if ($depth < 0) {
                $this->fail('css_unbalanced');
            }

            $this->pos++;
        }

        $this->fail('css_unbalanced');
    }

    /**
     * Pisah `$text` pada `$separator` tingkat atas (di luar string, kurung biasa, dan kurung siku).
     *
     * @return list<string>
     */
    private function splitTop(string $text, string $separator): array
    {
        $parts = [];
        $start = 0;
        $depth = 0;
        $i = 0;
        $n = strlen($text);

        while ($i < $n) {
            $i += strcspn($text, "\"'()[]".$separator, $i);

            if ($i >= $n) {
                break;
            }

            $char = $text[$i];

            if ($char === '"' || $char === "'") {
                $end = strpos($text, $char, $i + 1);
                $i = $end === false ? $n : $end + 1;

                continue;
            }

            if ($char === $separator && $depth === 0) {
                $parts[] = substr($text, $start, $i - $start);
                $start = $i + 1;
            } elseif ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;
            }

            $i++;
        }

        $parts[] = substr($text, $start);

        return $parts;
    }

    /**
     * @return list<string>
     */
    private function selectors(string $prelude): array
    {
        $selectors = [];

        foreach ($this->splitTop($prelude, ',') as $part) {
            $part = trim($part);
            $rest = str_starts_with($part, $this->scope) ? substr($part, strlen($this->scope)) : null;

            // Token kanonik penuh, lalu harus diikuti pemisah (bukan lanjutan nama), bukan sekadar substring.
            if ($rest === null || ($rest !== '' && preg_match('/^[\s>+~:\[]/', $rest) !== 1)) {
                $this->fail('css_selector', ['selector' => Str::limit($part, 60)]);
            }

            $this->assertCompound($rest, 0, $part);
            $selectors[] = $part;
        }

        return $selectors;
    }

    /**
     * Sisa selektor setelah awalan scope (atau isi argumen pseudo): hanya tipe, `*`, atribut, pseudo, dan kombinator.
     */
    private function assertCompound(string $selector, int $depth, string $whole): void
    {
        if ($depth > self::MAX_SELECTOR_DEPTH) {
            $this->fail('css_selector', ['selector' => Str::limit($whole, 60)]);
        }

        $i = 0;
        $n = strlen($selector);

        while ($i < $n) {
            $char = $selector[$i];

            if (ctype_alnum($char) || str_contains("-_ \t\r\n\f>+~*", $char)) {
                $i += strspn($selector, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_ '."\t\r\n\f>+~*", $i);

                continue;
            }

            if ($char === '[') {
                $end = $this->matching($selector, $i, '[', ']', $whole);
                $attribute = substr($selector, $i, $end - $i + 1);

                if (preg_match('/^\[\s*(?:data-[a-z0-9_-]+|aria-[a-z0-9_-]+|lang|dir)\s*(?:[~|^$*]?=\s*(?:"[^"]*+"|\'[^\']*+\'|[a-z0-9_-]+)\s*(?:[is]\s*)?)?\]\z/i', $attribute) !== 1) {
                    $this->fail('css_selector', ['selector' => Str::limit($whole, 60)]);
                }

                $i = $end + 1;

                continue;
            }

            if ($char === ':' && preg_match('/\G:{1,2}(-?[a-z][a-z0-9-]*)/Ai', $selector, $match, 0, $i) === 1) {
                $i += strlen($match[0]);

                if (($selector[$i] ?? '') === '(') {
                    $end = $this->matching($selector, $i, '(', ')', $whole);
                    $this->assertPseudoArgument(strtolower($match[1]), substr($selector, $i + 1, $end - $i - 1), $depth, $whole);
                    $i = $end + 1;
                }

                continue;
            }

            $this->fail('css_selector', ['selector' => Str::limit($whole, 60)]);
        }
    }

    private function assertPseudoArgument(string $name, string $argument, int $depth, string $whole): void
    {
        $valid = match ($name) {
            'is', 'where', 'not', 'has' => $this->assertSelectorArguments($argument, $depth, $whole),
            'nth-child', 'nth-last-child', 'nth-of-type', 'nth-last-of-type' => preg_match('/^\s*(?:[+-]?\d*n(?:\s*[+-]\s*\d+)?|[+-]?\d+|even|odd)\s*\z/i', $argument) === 1,
            'lang', 'dir' => preg_match('/^\s*[a-z0-9*-]+\s*\z/i', $argument) === 1,
            default => false,
        };

        if (! $valid) {
            $this->fail('css_selector', ['selector' => Str::limit($whole, 60)]);
        }
    }

    private function assertSelectorArguments(string $argument, int $depth, string $whole): bool
    {
        foreach ($this->splitTop($argument, ',') as $part) {
            if (trim($part) === '') {
                return false;
            }

            $this->assertCompound($part, $depth + 1, $whole);
        }

        return true;
    }

    /**
     * Indeks penutup yang cocok untuk pembuka di `$open` (string dilewati).
     */
    private function matching(string $text, int $open, string $opening, string $closing, string $whole): int
    {
        $depth = 0;
        $n = strlen($text);

        for ($i = $open; $i < $n; $i++) {
            $char = $text[$i];

            if ($char === '"' || $char === "'") {
                $end = strpos($text, $char, $i + 1);
                $i = $end === false ? $n : $end;
            } elseif ($char === $opening) {
                $depth++;
            } elseif ($char === $closing && --$depth === 0) {
                return $i;
            }
        }

        $this->fail('css_selector', ['selector' => Str::limit($whole, 60)]);
    }

    /**
     * @param  'rule'|'font-face'  $context
     * @return list<array{0: string, 1: string}> pasangan [properti, nilai]; properti huruf kecil kecuali custom property
     */
    private function declarations(string $body, string $context): array
    {
        $declarations = [];

        foreach ($this->splitTop($body, ';') as $raw) {
            $raw = trim($raw);

            if ($raw === '') {
                continue;
            }

            $colon = strpos($raw, ':');
            $property = $colon === false ? '' : trim(substr($raw, 0, $colon));
            $value = $colon === false ? '' : trim(substr($raw, $colon + 1));
            $custom = str_starts_with($property, '--');
            $property = $custom ? $property : strtolower($property);

            if ($value === '' || preg_match('/^(?:--[a-z0-9_-]+|-?[a-z][a-z0-9-]*)\z/i', $property) !== 1
                || in_array($property, self::DENIED_PROPERTIES, true)
                || ($context === 'font-face' && ! in_array($property, self::FONT_FACE_PROPERTIES, true))) {
                $this->fail('css_property', ['property' => Str::limit($property, 40)]);
            }

            $this->assertValue($property, $value, $context);
            $declarations[] = [$property, $value];
        }

        return $declarations;
    }

    private function assertValue(string $property, string $value, string $context): void
    {
        if (strlen($value) > self::MAX_VALUE_BYTES) {
            $this->fail('css_value_too_long', ['property' => Str::limit($property, 40), 'max' => self::MAX_VALUE_BYTES / 1024]);
        }

        $bare = preg_replace('/"[^"]*+"|\'[^\']*+\'/', '""', $value);

        if ($bare === null) {
            $this->fail('css_internal');
        }

        if (str_contains($bare, '!')) {
            $this->fail('css_important');
        }

        $found = preg_match_all('/([A-Za-z_-][A-Za-z0-9_-]*)\s*\(|"[^"]*+"|\'[^\']*+\'/', $value, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        if ($found === false) {
            $this->fail('css_internal');
        }

        foreach ($matches as $match) {
            if (! isset($match[1]) || $match[1][1] < 0) {
                continue;
            }

            $function = strtolower($match[1][0]);
            $end = $match[0][1] + strlen($match[0][0]);

            if (! in_array($function, self::FUNCTIONS, true)) {
                $this->fail('css_function', ['name' => Str::limit($function, 30)]);
            }

            if ($function === 'attr' && preg_match('/\G\s*[a-z_][a-z0-9_-]*\s*\)/Ai', $value, $ignored, 0, $end) !== 1) {
                $this->fail('css_function', ['name' => 'attr']);
            }

            if ($function === 'url') {
                if (preg_match('/\G\s*("[^"]*+"|\'[^\']*+\')\s*\)/A', $value, $uri, 0, $end) !== 1) {
                    $this->fail('css_url');
                }

                $this->assertUrl($property, substr($uri[1], 1, -1), $context);
            }
        }
    }

    private function assertUrl(string $property, string $uri, string $context): void
    {
        if ($context === 'font-face') {
            if ($property !== 'src' || preg_match(self::FONT_URL, $uri) !== 1) {
                $this->fail('css_url');
            }

            return;
        }

        if (preg_match(self::URL_PROPERTIES, $property) !== 1 || ! str_starts_with(strtolower($uri), 'data:image/svg+xml,')) {
            $this->fail('css_url');
        }

        $this->uriBytes += strlen($uri);

        if (strlen($uri) > self::MAX_URI_BYTES || $this->uriBytes > self::MAX_URI_TOTAL_BYTES) {
            $this->fail('css_uri_size', ['max' => self::MAX_URI_BYTES / 1024, 'total' => self::MAX_URI_TOTAL_BYTES / 1024]);
        }

        $body = substr($uri, strlen('data:image/svg+xml,'));
        $decoded = strtolower(rawurldecode($body));

        // Wajib terenkode-persen (tanpa < > mentah), berakar <svg>, dan tanpa unsur/atribut yang dapat memuat sumber luar atau menjalankan sesuatu.
        if (preg_match('/[<>"\x00-\x1f]/', $body) === 1
            || preg_match('/^\s*<svg[\s>]/', $decoded) !== 1
            || preg_match('/<\s*(?:script|foreignobject|image|use|style)|<[!?]|&|href|\bon[a-z]+\s*=|url\(\s*+(?!["\']?#)/', $decoded) !== 0) {
            $this->fail('css_svg');
        }
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    private function fail(string $key, array $replace = []): never
    {
        throw new InvalidArgumentException(__("pondok.theme_assets.{$key}", $replace));
    }
}
