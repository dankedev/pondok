<?php

use App\Actions\Konten\BuildColorScale;
use App\Actions\Konten\GetPortalTheme;
use App\Actions\Konten\ThemePalette;
use App\Actions\Konten\ValidatePortalTheme;
use App\Models\Setting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

/*
| F104 VERIFY: celah di luar test RED. Tema v1 nyata (Amanah/Lapang 1.0.0, salinan di tests/Fixtures/portal-themes-v1),
| kontras permukaan krem dengan primer admin ekstrem, injeksi, dan tema yang berganti/hilang/diubah antar request.
*/

beforeEach(function () {
    portalBoot();
    themeBuild();
});

/**
 * @return array{css: ?string, font_aliases: ?list<string>}
 */
function lookVerify(): array
{
    app()->forgetScopedInstances();

    return Arr::only(app(GetPortalTheme::class)->handle(), ['css', 'font_aliases']);
}

it('F104 VERIFY AC-1: the real Amanah and Lapang 1.0.0 themes stay valid and change nothing in the look', function () {
    $dir = themeSandbox();

    foreach (['amanah', 'lapang'] as $slug) {
        File::copyDirectory(base_path("tests/Fixtures/portal-themes-v1/{$slug}"), "{$dir}/{$slug}");
        $tree = app(ValidatePortalTheme::class)->handle("{$dir}/{$slug}/theme.json");

        expect($tree['palette'])->toBeNull()->and($tree['surface'])->toBeNull()->and($tree['font'])->toBeNull();
    }

    foreach ([[], ['primary' => '#1f2a5e', 'accent' => '#ffd452', 'font' => 'nunito', 'radius' => 'large']] as $values) {
        themeSet(['primary' => null, 'accent' => null, 'font' => null, 'radius' => null, ...$values]);
        themeActivate(null);
        $baseline = lookVerify();

        foreach (['amanah', 'lapang'] as $slug) {
            themeActivate($slug);

            expect(lookVerify())->toBe($baseline, $slug);
        }
    }
});

it('F104 VERIFY AC-3: cream surfaces keep the brand text readable even for extreme admin primaries', function () {
    $surfaces = ThemePalette::SURFACES['krem'];
    $failures = [];

    foreach (['#000000', '#ffffff', '#ffff00', '#00ff00', '#808080', '#ff0000', '#00ffff', '#7fff00', '#1f6b4f', '#d2b48c', '#f5f5dc'] as $hex) {
        $tokens = GetPortalTheme::brandTokens($hex);

        if ($tokens === null) {
            continue;
        }

        foreach ($surfaces as $token => $surface) {
            $ratio = BuildColorScale::contrast($tokens['brand_text'], $surface);

            if ($ratio < BuildColorScale::MIN_CONTRAST) {
                $failures[] = sprintf('%s brand_text %s di atas %s = %.2f', $hex, $tokens['brand_text'], $token, $ratio);
            }
        }
    }

    expect($failures)->toBe([]);
});

it('F104 VERIFY AC-3: any admin primary or accent over the green and cream theme gives a scale that passes the contrast rule', function () {
    themeInstall([...themeBaseManifest(), 'min_core' => '1.1.0', 'palette' => 'hijau', 'surface' => 'krem'], 'zz-uji-look');
    themeActivate('zz-uji-look');

    $inputs = [[null, null]];

    foreach (['#000000', '#ffffff', '#ffff00', '#808080', '#fffff0', '#0a0a0a', '#00ff00'] as $hex) {
        $inputs[] = [$hex, null];
        $inputs[] = [null, $hex];
        $inputs[] = [$hex, '#f8b800'];
    }

    foreach ($inputs as [$primary, $accent]) {
        $label = json_encode([$primary, $accent]);
        themeSet(['primary' => $primary, 'accent' => $accent]);

        expect(fn () => lookVerify())->not->toThrow(Throwable::class, $label);

        $css = lookVerify()['css'];
        preg_match_all('/--color-(primary|accent)-(\d+): (#[0-9a-f]{6});/', (string) $css, $m, PREG_SET_ORDER);
        $scales = ['primary' => BuildColorScale::DEFAULT_PRIMARY, 'accent' => BuildColorScale::DEFAULT_ACCENT];
        $found = [];

        foreach ($m as [, $role, $step, $hex]) {
            $found[$role][(int) $step] = $hex;
        }

        expect(BuildColorScale::contrastFailure($found['primary'] ?? $scales['primary'], $found['accent'] ?? $scales['accent']))->toBeNull($label)
            ->and($css)->toContain('--color-gray-50: ');
    }
});

it('F104 VERIFY AC-5: a theme that is deleted, edited in place or replaced shows its new look on the next request, never a stale one', function () {
    $baseline = (function () {
        themeActivate(null);

        return lookVerify();
    })();
    $slug = themeInstall([...themeBaseManifest(), 'min_core' => '1.1.0', 'palette' => 'hijau', 'surface' => 'krem', 'font' => 'lora'], 'zz-uji-ganti');
    themeActivate($slug);
    $green = lookVerify();

    expect($green['css'])->toContain('--color-gray-50')->toContain('--font-heading');

    themeInstall([...themeBaseManifest(), 'min_core' => '1.1.0', 'surface' => 'putih'], $slug);
    expect(lookVerify())->toBe($baseline, 'tema diedit di tempat: palet, krem, dan font hilang');

    themeInstall([...themeBaseManifest(), 'min_core' => '1.1.0', 'palette' => 'hijau'], $slug);
    expect(lookVerify()['css'])->not->toBe($baseline['css'])->not->toContain('--color-gray-');

    Setting::set('theme.template', 'default');
    expect(lookVerify())->toBe($baseline, 'tema diganti ke default');

    themeActivate($slug);
    File::deleteDirectory(themeSandbox()."/{$slug}");
    expect(lookVerify())->toBe($baseline, 'folder tema hilang');
});

it('F104 VERIFY AC-2: hostile palette, surface and font values never reach the portal HTML and the page still renders', function () {
    portalSeedContent();
    $payload = "lora');}</style><script>alert(1)</script>";
    $slug = themeInstall([...themeBaseManifest(), 'min_core' => '1.1.0', 'palette' => '#ff0000;}</style><b id=x>', 'surface' => 'url(//evil.test/a.png)', 'font' => $payload], 'zz-uji-jahat');
    themeActivate($slug);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toContain('alert(1)')->not->toContain('evil.test')->not->toContain('<b id=x>')->not->toContain('#ff0000')
        ->and(lookVerify())->toBe(['css' => null, 'font_aliases' => null]);
});

it('F104 VERIFY AC-4: themed css only has map hexes and server font names, inside one style element', function () {
    portalSeedContent();
    themeActivate(themeInstall([...themeBaseManifest(), 'min_core' => '1.1.0', 'palette' => 'hijau', 'surface' => 'krem', 'font' => 'nunito'], 'zz-uji-css'));

    $html = $this->get('/')->assertOk()->getContent();

    expect(substr_count($html, 'data-portal-theme'))->toBe(1)
        ->and(preg_match('#<style data-portal-theme>(:root\{[^<>{}\\\\@]*\})</style>#', $html, $m))->toBe(1);

    preg_match_all('/(--[a-z0-9-]+): ([^;]+);/', $m[1], $declarations, PREG_SET_ORDER);

    foreach ($declarations as [, $name, $value]) {
        expect($name)->toMatch('/^--(color-(primary|brand|on-brand|brand-hover|brand-text|gray)(-\d+)?|font-sans|font-heading)\z/')
            ->and($value)->toMatch('/^(#[0-9a-f]{6}|\'Nunito\'[^;]*)\z/', $name);
    }

});

it('F104 VERIFY AC-7: a themed page has no csrf token, xsrf, livewire asset or cookie, with admin colours set too', function () {
    portalSeedContent();
    themeSet(['primary' => '#1f2a5e', 'accent' => '#f8b800', 'font' => 'lora']);
    themeActivate(themeInstall([...themeBaseManifest(), 'min_core' => '1.1.0', 'palette' => 'hijau', 'surface' => 'krem', 'font' => 'nunito'], 'zz-uji-aman'));

    foreach (['/', '/berita'] as $url) {
        $response = $this->get($url)->assertOk();
        $html = strtolower($response->getContent());

        expect($response->headers->get('Cache-Control'))->toContain('public')
            ->and($response->headers->get('ETag'))->not->toBeNull()
            ->and($response->headers->getCookies())->toBeEmpty($url)
            ->and($response->headers->has('Set-Cookie'))->toBeFalse($url);

        foreach (['csrf-token', 'xsrf', '_token', 'livewire'] as $needle) {
            expect($html)->not->toContain($needle, "{$url}: {$needle}");
        }
    }
});

it('F104 VERIFY AC-7: changing only the palette, surface or admin colour changes the etag so a stale 304 is never served', function () {
    portalSeedContent();
    $slug = themeInstall([...themeBaseManifest(), 'min_core' => '1.1.0', 'palette' => 'hijau'], 'zz-uji-etag');
    themeActivate($slug);
    $etags = [$this->get('/')->headers->get('ETag')];

    themeInstall([...themeBaseManifest(), 'min_core' => '1.1.0', 'palette' => 'hijau', 'surface' => 'krem'], $slug);
    app()->forgetScopedInstances();
    $etags[] = $this->get('/')->headers->get('ETag');

    themeSet(['primary' => '#1f2a5e']);
    app()->forgetScopedInstances();
    $response = $this->withHeaders(['If-None-Match' => $etags[1]])->get('/');
    $etags[] = $response->headers->get('ETag');

    expect($response->getStatusCode())->toBe(200)->and(count(array_unique($etags)))->toBe(3);
});
