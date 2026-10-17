<?php

use App\Actions\Prestasi\PublishAchievementAsPost;
use App\Models\Category;
use App\Models\Post;

/*
| F049 VERIFY: celah taksonomi berita. `portalBoot()` dan helper `blk*` dari tests/Pest.php.
*/

beforeEach(function () {
    $this->f = portalBoot();
});

function f049Section(string $html, string $needle): string
{
    preg_match_all('#<section\b.*?</section>#is', $html, $matches);

    return collect($matches[0])->first(fn (string $section): bool => str_contains($section, $needle), '');
}

it('VERIFY: odd jenis values are ignored and never leak into /berita links (array kategori stays 404)', function () {
    $cat = kontenCategory('post', 'Tahfidz');
    foreach (range(1, 10) as $i) {
        portalPost(['title' => "Berita {$i}", 'category_id' => $cat->id]);
    }

    foreach (['?jenis[]=prestasi', '?jenis=', '?jenis=<script>', '?jenis=prestasi&kategori=tahfidz&page=2'] as $query) {
        $html = $this->get('/berita'.$query)->assertOk()->getContent();

        expect($html)->not->toContain('jenis=')->not->toContain('<script>', $query);
    }

    $this->get('/berita?kategori[]=tahfidz')->assertNotFound();
});

it('VERIFY: kategori and jenjang filters stack and only show matching terbit posts', function () {
    $tahfidz = kontenCategory('post', 'Tahfidz');
    $lain = kontenCategory('post', 'Lain');
    portalPost(['title' => 'Cocok Dua', 'category_id' => $tahfidz->id, 'unit_id' => $this->f->sd->id]);
    portalPost(['title' => 'Salah Jenjang', 'category_id' => $tahfidz->id, 'unit_id' => $this->f->sltp->id]);
    portalPost(['title' => 'Salah Kategori', 'category_id' => $lain->id, 'unit_id' => $this->f->sd->id]);
    portalPost(['title' => 'Draf Cocok', 'category_id' => $tahfidz->id, 'unit_id' => $this->f->sd->id, 'status' => 'draft', 'published_at' => null]);

    $this->get('/berita?kategori=tahfidz&jenjang=sd')->assertOk()
        ->assertSee('Cocok Dua')->assertDontSee('Salah Jenjang')->assertDontSee('Salah Kategori')->assertDontSee('Draf Cocok');
});

it('VERIFY: the prestasi see-all link ignores an album category with the prestasi slug', function () {
    kontenCategory('album', 'Prestasi');
    portalAchievementPost(['title' => 'Juara Satu']);
    blkSet([blk('feed', ['source' => 'achievements'])]);

    $section = f049Section(blkHome(), __('pondok.portal.home.latest_achievements'));

    expect($section)->not->toContain('kategori=prestasi')->toContain('href="'.route('portal.posts.index').'"');
});

it('VERIFY: posts feed omits achievement posts and achievements feed omits manual posts, even in the Prestasi category', function () {
    $prestasi = kontenCategory('post', 'Prestasi');
    portalPost(['title' => 'Manual Prestasi', 'category_id' => $prestasi->id]);
    portalAchievementPost(['title' => 'Dari Prestasi']);

    blkSet([blk('feed', ['source' => 'posts']), blk('feed', ['source' => 'achievements'])]);
    $home = blkHome();

    expect(f049Section($home, __('pondok.portal.home.latest_news')))->toContain('Manual Prestasi')->not->toContain('Dari Prestasi')
        ->and(f049Section($home, __('pondok.portal.home.latest_achievements')))->toContain('Dari Prestasi')->not->toContain('Manual Prestasi');
});

it('VERIFY: publishing reuses a Prestasi category with another slug and ignores an album category of the same name', function () {
    $f = prestasiFixture();
    $existing = kontenCategory('post', 'prestasi', 'juara');
    kontenCategory('album', 'Prestasi');
    $achievement = prestasiCreate($f, [[$f->s2, 'Juara 1']], ['verified' => true, 'public' => true, 'title' => 'Juara Satu']);
    $this->actingAs($f->c);

    $post = app(PublishAchievementAsPost::class)->handle($achievement, $f->c);

    expect($post->category_id)->toBe($existing->id)
        ->and(Category::query()->where('type', 'post')->count())->toBe(1);
});

it('VERIFY: publishing creates a post category when only an album category named Prestasi exists', function () {
    $f = prestasiFixture();
    kontenCategory('album', 'Prestasi');
    $achievement = prestasiCreate($f, [[$f->s2, 'Juara 1']], ['verified' => true, 'public' => true, 'title' => 'Juara Dua']);
    $this->actingAs($f->c);

    $post = app(PublishAchievementAsPost::class)->handle($achievement, $f->c);

    expect($post->category->type)->toBe('post')->and($post->category->name)->toBe('Prestasi')
        ->and(Post::query()->count())->toBe(1);
});
