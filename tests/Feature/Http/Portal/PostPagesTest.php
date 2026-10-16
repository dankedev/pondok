<?php

use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->f = portalBoot();
});

function portalAt(string $date): CarbonImmutable
{
    return CarbonImmutable::parse("{$date} 08:00", 'Asia/Jakarta');
}

it('AC-7: lists only published posts newest first', function () {
    portalPost(['title' => 'Judul Alpha', 'type' => 'berita', 'published_at' => portalAt('2026-10-14')]);
    portalPost(['title' => 'Judul Beta', 'type' => 'kegiatan', 'published_at' => portalAt('2026-10-13')]);
    portalPost(['title' => 'Judul Gamma', 'type' => 'prestasi', 'published_at' => portalAt('2026-10-12')]);
    kontenPost(['title' => 'Judul Draf']);
    portalPost(['title' => 'Judul Jadwal', 'published_at' => portalAt('2026-10-20')]);
    portalPost(['title' => 'Judul Hapus'])->delete();

    $html = $this->get('/berita')->assertOk()->getContent();

    expect(strpos($html, 'Judul Alpha'))->toBeInt()
        ->and(strpos($html, 'Judul Beta'))->toBeGreaterThan(strpos($html, 'Judul Alpha'))
        ->and(strpos($html, 'Judul Gamma'))->toBeGreaterThan(strpos($html, 'Judul Beta'))
        ->and($html)->not->toContain('Judul Draf')->not->toContain('Judul Jadwal')->not->toContain('Judul Hapus')
        ->toContain('14 Okt 2026')
        ->toContain('datetime="2026-10-14T08:00:00+07:00"');
});

it('AC-8: paginates and keeps filters', function () {
    foreach (range(1, 10) as $i) {
        portalPost(['title' => sprintf('Kabar nomor %02d', $i), 'published_at' => portalAt('2026-10-14')->subMinutes($i)]);
    }

    $first = $this->get('/berita?jenis=berita')->assertOk();
    expect(substr_count($first->getContent(), 'Kabar nomor'))->toBe(9);
    $first->assertDontSee('Kabar nomor 10');
    expect(portalDecoded($first))->toContain('jenis=berita&page=2');

    $this->get('/berita?page=2')->assertOk()->assertSee('Kabar nomor 10')->assertDontSee('Kabar nomor 01');
});

it('AC-9: filters by type, category and unit', function () {
    $tahfidz = kontenCategory('post', 'Tahfidz');
    portalPost(['title' => 'Berita Umum', 'type' => 'berita']);
    portalPost(['title' => 'Kegiatan Pramuka', 'type' => 'kegiatan']);
    portalPost(['title' => 'Berita Tahfidz', 'type' => 'berita', 'category_id' => $tahfidz->id]);
    portalPost(['title' => 'Berita SLTP', 'type' => 'berita', 'unit_id' => $this->f->sltp->id]);
    portalPost(['title' => 'Kegiatan SLTP', 'type' => 'kegiatan', 'unit_id' => $this->f->sltp->id]);
    portalPost(['title' => 'Berita SD', 'type' => 'berita', 'unit_id' => $this->f->sd->id]);

    $jenis = $this->get('/berita?jenis=kegiatan')->assertOk();
    $jenis->assertSee('Kegiatan Pramuka')->assertDontSee('Berita Umum')->assertSeeText(__('pondok.portal.posts.titles.kegiatan'));
    expect($jenis->getContent())->toContain('aria-current="true"');

    $this->get('/berita?kategori=tahfidz')->assertOk()->assertSee('Berita Tahfidz')->assertDontSee('Berita Umum');

    $this->get('/berita?jenjang=sltp')->assertOk()
        ->assertSee('Berita SLTP')->assertSee('Kegiatan SLTP')
        ->assertDontSee('Berita Umum')->assertDontSee('Berita SD');

    $this->get('/berita?jenis=berita&jenjang=sltp')->assertOk()
        ->assertSee('Berita SLTP')->assertDontSee('Kegiatan SLTP')->assertDontSee('Berita SD');
});

it('AC-10: summarizes posts without author', function () {
    $author = User::factory()->create(['name' => 'Penulis Rahasia']);
    $long = '<p>'.str_repeat('kata ', 60).'</p><script>x</script>';
    portalPost(['title' => 'Tanpa Ringkasan', 'body' => $long, 'excerpt' => null, 'author_id' => $author->id]);
    portalPost(['title' => 'Dengan Ringkasan', 'excerpt' => 'Ringkasan manual tampil', 'author_id' => $author->id]);

    $list = $this->get('/berita')->assertOk();
    $list->assertSee('Ringkasan manual tampil')->assertDontSee('Penulis Rahasia');
    expect($list->getContent())->toContain('…')->not->toContain('<p>kata');

    $this->get('/berita/dengan-ringkasan')->assertOk()->assertDontSee('Penulis Rahasia');
});

it('AC-11: shows post detail', function () {
    $category = kontenCategory('post', 'Tahfidz');
    portalPost([
        'title' => 'Wisuda Tahfidz', 'type' => 'prestasi', 'category_id' => $category->id, 'unit_id' => $this->f->sltp->id,
        'cover_path' => 'posts/covers/c.jpg', 'body' => '<p>Isi lengkap</p>',
    ]);
    Storage::disk('public')->put('posts/covers/c.jpg', 'x');

    $response = $this->get('/berita/wisuda-tahfidz')->assertOk();
    $cover = Storage::disk('public')->url('posts/covers/c.jpg');

    expect($response->getContent())
        ->toMatch('/<h1[^>]*>\s*Wisuda Tahfidz\s*<\/h1>/')
        ->toContain(__('pondok.post.types.prestasi'))
        ->toContain('14 Okt 2026')
        ->toContain('/berita?kategori=tahfidz')
        ->toContain('<img src="'.$cover.'" alt="Wisuda Tahfidz"')
        ->toMatch('/class="[^"]*portal-prose[^"]*isolate[^"]*overflow-hidden[^"]*"/')
        ->toContain('<p>Isi lengkap</p>');
    $response->assertSee('SLTP');
});

it('AC-12: escapes text and re-sanitizes body', function () {
    portalPost([
        'title' => '<b>Judul</b>', 'slug' => 'judul-xss', 'excerpt' => '<i>x</i>',
        'body' => '<p>Halo</p><script>alert(1)</script><img src="x" onerror="alert(1)">',
    ]);

    $response = $this->get('/berita/judul-xss')->assertOk();

    expect($response->getContent())
        ->toContain('&lt;b&gt;Judul&lt;/b&gt;')
        ->toContain('<p>Halo</p>')
        ->not->toContain('<script>alert(1)')
        ->not->toContain('onerror')
        ->not->toContain('<b>Judul</b>');

    Setting::set('school.name', '<script>x</script>');
    $home = $this->get('/')->assertOk()->getContent();
    expect($home)->toContain('&lt;script&gt;x&lt;/script&gt;')->not->toContain('<script>x</script>');
});

it('AC-13: embeds youtube with nocookie', function () {
    portalPost(['title' => 'Dengan Video', 'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);

    $html = $this->get('/berita/dengan-video')->assertOk()->getContent();

    expect($html)
        ->toContain('<iframe')
        ->toContain('src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"')
        ->toContain('loading="lazy"')
        ->toMatch('/<iframe[^>]*title="[^"]+"/')
        ->toContain('referrerpolicy="strict-origin-when-cross-origin"')
        ->toContain('https://www.youtube.com/watch?v=dQw4w9WgXcQ')
        ->not->toContain('www.youtube.com/embed');
});
