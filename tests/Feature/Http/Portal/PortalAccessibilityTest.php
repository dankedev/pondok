<?php

use App\Models\StaticPage;

beforeEach(function () {
    portalBoot();
    $c = portalSeedContent();
    foreach (range(1, 10) as $i) {
        portalPost(['title' => "Kabar tambahan {$i}"]);
    }
    portalPost(['title' => 'Berita Video', 'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);
    kontenVideo(['title' => 'Video Unggulan', 'youtube_id' => 'AAAAAAAAAAA', 'is_featured' => true]);
    StaticPage::findBySlug('profil')->update(['body' => '<p>Profil kami.</p>']);
});

dataset('portalPages', [
    'home' => ['/', 'portal.home'],
    'posts' => ['/berita', 'portal.posts.index'],
    'posts page 2' => ['/berita?page=2', 'portal.posts.index'],
    'post' => ['/berita/wisuda-2026', 'portal.posts.index'],
    'post with video' => ['/berita/berita-video', 'portal.posts.index'],
    'albums' => ['/galeri', 'portal.albums.index'],
    'album' => ['/galeri/album-ramadan', 'portal.albums.index'],
    'videos' => ['/video', 'portal.videos.index'],
    'profil' => ['/profil', 'portal.pages.show'],
    'visi-misi' => ['/visi-misi', 'portal.pages.show'],
    'kontak' => ['/kontak', 'portal.pages.show'],
    '404' => ['/tidak-ada-halaman', null],
]);

it('AC-30: renders accessible markup', function (string $url, ?string $activeRoute) {
    $response = $this->get($url);
    $html = $response->getContent();

    expect($response->getStatusCode())->toBe($url === '/tidak-ada-halaman' ? 404 : 200);
    expect(preg_match_all('/<h1[\s>]/i', $html))->toBe(1);
    expect($html)->toContain('<html lang="id"')
        ->toContain('<meta name="viewport" content="width=device-width, initial-scale=1">')
        ->toContain('href="#konten"')->toContain('<main id="konten"')->toContain('<details');

    $labels = portalNavLabels($html);
    expect($labels)->not->toBeEmpty()->not->toContain('')->and(array_unique($labels))->toHaveCount(count($labels));

    expect(preg_match_all('/<img\b(?![^>]*\balt=)[^>]*>/i', $html))->toBe(0);
    expect(preg_match_all('/<iframe\b(?![^>]*\btitle="[^"]+")[^>]*>/i', $html))->toBe(0);

    if ($url === '/tidak-ada-halaman') {
        expect($html)->not->toContain('aria-current="page"');
    } else {
        expect($html)->toContain('aria-current="page"');
    }
})->with('portalPages');

it('AC-31: has no raw translation keys', function (string $url) {
    $html = $this->get($url)->getContent();

    expect($html)->not->toMatch('/pondok\.[a-z_]+\.[a-z_]/')->not->toContain('Previous')->not->toContain('Next')->not->toContain('Showing');
})->with(array_map(fn (array $row): string => $row[0], array_values(['home' => ['/'], 'p' => ['/berita'], 'p2' => ['/berita?page=2'], 'post' => ['/berita/wisuda-2026'], 'a' => ['/galeri'], 'al' => ['/galeri/album-ramadan'], 'v' => ['/video'], 'pr' => ['/profil'], 'vm' => ['/visi-misi'], 'k' => ['/kontak'], '404' => ['/tidak-ada-halaman']])));

it('AC-32: uses design tokens only in portal views', function () {
    $files = [
        ...glob(resource_path('views/portal/*.blade.php')) ?: [],
        ...glob(resource_path('views/portal/**/*.blade.php')) ?: [],
        ...glob(resource_path('views/components/portal/*.blade.php')) ?: [],
        resource_path('views/components/ui/empty-state.blade.php'),
        resource_path('views/layouts/public.blade.php'),
        resource_path('views/errors/404.blade.php'),
    ];

    expect(count(array_filter($files, 'is_file')))->toBeGreaterThan(8);

    foreach (array_filter($files, 'is_file') as $file) {
        $source = file_get_contents($file);
        $name = str_replace(resource_path('views/'), '', $file);

        expect($source)->not->toContain('dark:', "dark: di {$name}")->not->toContain('!important', "!important di {$name}");
        expect(preg_match('/\b(?:bg|text|border|ring|from|to|fill|stroke)-(?:red|green|emerald|amber|sky|zinc|slate|blue|yellow|indigo|neutral|stone)-\d{2,3}\b/', $source))->toBe(0, "warna mentah di {$name}");
        expect(preg_match('/(?:class|style)="[^"]*#[0-9a-fA-F]{3,8}\b/', $source))->toBe(0, "hex di {$name}");
    }

    foreach (['portal/posts/show.blade.php', 'portal/pages/show.blade.php'] as $view) {
        expect(substr_count(file_get_contents(resource_path("views/{$view}")), '{!!'))->toBe(1);
    }

    expect(file_get_contents(resource_path('css/app.css')))->toMatch('/\.portal-prose\s*\{[^}]*contain:\s*paint/');

    $layout = file_get_contents(resource_path('views/layouts/public.blade.php'));
    foreach (['@livewireStyles', '@livewireScripts', 'csrf_token', 'csrf-token', '@csrf'] as $needle) {
        expect($layout)->not->toContain($needle);
    }
});
