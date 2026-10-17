<?php

use App\Models\Category;
use App\Models\Setting;
use App\Models\StaticPage;
use Database\Seeders\ContentCategorySeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Demo\DemoContent;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\StaticPageSeeder;
use Illuminate\Support\Facades\Storage;

it('AC-3: seeds the three static pages without overwriting edits', function () {
    $this->seed(StaticPageSeeder::class);

    expect(StaticPage::query()->count())->toBe(3)
        ->and(StaticPage::query()->orderBy('id')->pluck('title', 'slug')->all())->toBe([
            'profil' => 'Profil',
            'visi-misi' => 'Visi & Misi',
            'kontak' => 'Kontak',
        ])
        ->and(StaticPage::query()->pluck('body')->unique()->all())->toBe(['']);

    StaticPage::query()->where('slug', 'profil')->update(['body' => '<p>Sudah diisi</p>']);

    $this->seed(StaticPageSeeder::class);

    expect(StaticPage::query()->count())->toBe(3)
        ->and(StaticPage::query()->where('slug', 'profil')->value('body'))->toBe('<p>Sudah diisi</p>');
});

it('AC-3: seeds the default album categories idempotently', function () {
    $this->seed(ContentCategorySeeder::class);
    $this->seed(ContentCategorySeeder::class);

    expect(Category::query()->orderBy('name')->get(['type', 'name'])->map(fn (Category $c): array => [$c->type, $c->name])->all())
        ->toBe([['album', 'Fasilitas'], ['album', 'Kegiatan']]);
});

it('AC-3: calls both seeders from the database seeder', function () {
    $this->seed(DatabaseSeeder::class);

    expect(StaticPage::query()->pluck('slug')->sort()->values()->all())->toBe(['kontak', 'profil', 'visi-misi'])
        ->and(Category::query()->where('type', 'album')->pluck('name')->sort()->values()->all())->toBe(['Fasilitas', 'Kegiatan']);
});

it('AC-4: enables the konten module on a fresh install and keeps other defaults', function () {
    Setting::query()->delete();

    $this->seed(SettingSeeder::class);

    expect(Setting::moduleEnabled('konten'))->toBeTrue()
        ->and(SettingSeeder::ENABLED_BY_DEFAULT)->toContain('konten')
        ->and(Setting::moduleEnabled('akademik'))->toBeTrue()
        ->and(Setting::moduleEnabled('asrama'))->toBeTrue()
        ->and(Setting::moduleEnabled('psb'))->toBeTrue()
        ->and(Setting::moduleEnabled('keuangan'))->toBeTrue()
        ->and(Setting::moduleEnabled('dokumen'))->toBeTrue()
        ->and(Setting::moduleEnabled('prestasi'))->toBeTrue();
});

it('AC-4: does not overwrite a konten switch that was turned off', function () {
    $this->seed(SettingSeeder::class);
    Setting::set('modules.konten.enabled', false);

    $this->seed(SettingSeeder::class);

    expect(Setting::moduleEnabled('konten'))->toBeFalse();
});

it('AC-3 (F037): seeds system pages with draft profile and published contact', function () {
    $this->seed(StaticPageSeeder::class);

    $pages = StaticPage::query()->get()->keyBy('slug');

    expect($pages->keys()->sort()->values()->all())->toBe(['kontak', 'profil', 'visi-misi'])
        ->and($pages['profil']->status)->toBe('draft')
        ->and($pages['visi-misi']->status)->toBe('draft')
        ->and($pages['kontak']->status)->toBe('terbit')
        ->and($pages->pluck('title', 'slug')->all())->toBe([
            'profil' => __('pondok.static_page.defaults.profil'),
            'visi-misi' => __('pondok.static_page.defaults.visi-misi'),
            'kontak' => __('pondok.static_page.defaults.kontak'),
        ])
        ->and($pages->pluck('body')->unique()->all())->toBe([''])
        ->and(Setting::query()->whereIn('key', ['portal.navigation_primary', 'portal.footer_widgets', 'portal.header_button'])->count())->toBe(0);

    StaticPage::query()->where('slug', 'profil')->update(['status' => 'terbit', 'body' => '<p>Diisi</p>']);
    StaticPage::query()->where('slug', 'kontak')->update(['status' => 'draft']);

    $this->seed(StaticPageSeeder::class);

    expect(StaticPage::query()->where('slug', 'profil')->first())->status->toBe('terbit')->body->toBe('<p>Diisi</p>')
        ->and(StaticPage::query()->where('slug', 'kontak')->value('status'))->toBe('draft')
        ->and(Setting::query()->whereIn('key', ['portal.navigation_primary', 'portal.footer_widgets', 'portal.header_button'])->count())->toBe(0);
});

it('AC-2 (F048): seeds kontak as a contact page and the others as default, without overwriting a changed template', function () {
    $this->seed(StaticPageSeeder::class);

    expect(StaticPage::query()->pluck('template', 'slug')->all())->toBe(['profil' => 'default', 'visi-misi' => 'default', 'kontak' => 'contact']);

    StaticPage::query()->where('slug', 'kontak')->update(['template' => 'default', 'title' => 'Hubungi Kami', 'status' => 'draft']);
    StaticPage::query()->where('slug', 'profil')->update(['template' => 'contact']);

    $this->seed(StaticPageSeeder::class);

    expect(StaticPage::query()->pluck('template', 'slug')->all())->toBe(['profil' => 'contact', 'visi-misi' => 'default', 'kontak' => 'default'])
        ->and(StaticPage::findBySlug('kontak'))->title->toBe('Hubungi Kami')->status->toBe('draft');
});

/**
 * Isi halaman Kontak demo versi lama (F037): mengetik ulang alamat, telepon, WhatsApp, dan email demo.
 */
function oldDemoContactBody(): string
{
    return '<p>Jl. Raya Belitang–Martapura, Mojosari, Belitang, OKU Timur, Sumatera Selatan</p><ul><li>Telepon: 0735 000 0170</li><li>WhatsApp: 0812 0000 0170</li><li>Email: info@alkhair-belitang.test</li></ul><h2>Jam layanan tata usaha</h2><p>Senin sampai Kamis dan Sabtu pukul 08.00 sampai 14.00. Jumat pukul 08.00 sampai 11.00.</p>';
}

it('AC-8 (F044): the demo contact page holds only the service hours and never retypes address, phone or email', function () {
    $this->seed(StaticPageSeeder::class);
    Setting::set('school.address', 'Alamat demo');

    (new DemoContent(new DemoContext))->profile();

    $body = (string) StaticPage::findBySlug('kontak')->body;

    expect($body)->toContain('Jam layanan')
        ->not->toContain('Mojosari')->not->toContain('0735')->not->toContain('0812 0000 0170')->not->toContain('info@alkhair-belitang.test');
});

it('AC-8 (F044): repairs the old demo contact page, stays idempotent, and leaves an admin-written contact page alone', function () {
    $this->seed(StaticPageSeeder::class);
    Setting::set('school.address', 'Alamat demo');
    $page = StaticPage::findBySlug('kontak');
    $page->update(['body' => oldDemoContactBody()]);

    (new DemoContent(new DemoContext))->profile();
    $repaired = (string) $page->fresh()->body;
    (new DemoContent(new DemoContext))->profile();

    expect($repaired)->toContain('Jam layanan')->not->toContain('Mojosari')->not->toContain('info@alkhair-belitang.test')->not->toContain('0735')
        ->and((string) $page->fresh()->body)->toBe($repaired);

    $custom = '<p>Datang langsung ke kantor kami di Jl. Mawar 5. Telepon 0711 123 456.</p>';
    $page->update(['body' => $custom]);

    (new DemoContent(new DemoContext))->profile();

    expect((string) $page->fresh()->body)->toBe($custom);
});

/**
 * Kata yang tidak boleh ada di blok beranda demo (basmalah, sapaan, hari besar di luar manhaj proyek).
 *
 * @return list<string>
 */
function blkDemoForbiddenWords(): array
{
    return ['bismillah', 'بسم', 'ahlan', 'assalamu', 'السلام', 'maulid', 'isra', 'nisfu', 'haul', 'tahlil', 'harlah', 'hadrah'];
}

/**
 * Konteks demo dengan akun aktor yang dibutuhkan `DemoContent` (biasanya dibuat `DemoSeeder`).
 */
function blkDemoContext(): DemoContext
{
    $context = new DemoContext;

    foreach (['super-admin', 'admin-konten'] as $role) {
        $context->users[$role] = kontenUser($role);
    }

    return $context;
}

it('AC-14 (F045): the demo seeds a fictional home with the block types except video, once, and without basmalah or greeting', function () {
    Storage::fake('public');
    $this->seed([RolePermissionSeeder::class, StaticPageSeeder::class, ContentCategorySeeder::class]);
    $context = blkDemoContext();

    (new DemoContent($context))->run();
    $first = blkStored();
    (new DemoContent($context))->run();

    $types = array_column($first, 'type');
    $quote = collect($first)->firstWhere('type', 'quote');
    $json = mb_strtolower(json_encode($first, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    expect($types)->toContain('hero', 'text_image', 'features', 'quote', 'cta', 'feed')->and(array_count_values($types)['hero'])->toBe(1)
        // Video demo sengaja tidak dibuat (DemoSeederTest: videos kosong), jadi blok `video` tidak ikut.
        ->and($types)->not->toContain('video')
        ->and(array_unique(array_column($first, 'visible')))->toBe([true])
        ->and(blkStored())->toEqual($first)
        ->and($quote['data']['rtl'])->toBeTrue()
        ->and($quote['data']['text'])->toMatch('/\p{Arabic}/u')
        ->and((string) $quote['data']['source'])->toContain('104')
        ->and(preg_match('/ali\s*.?imr/iu', (string) $quote['data']['source']))->toBe(1)
        ->and(profilRawHas('portal.hero_greeting'))->toBeFalse();

    foreach (blkDemoForbiddenWords() as $word) {
        expect($json)->not->toContain($word);
    }

    // Blok demo lolos validasi apa adanya.
    blkSave($first);

    expect(blkStored())->toEqual($first);
});
