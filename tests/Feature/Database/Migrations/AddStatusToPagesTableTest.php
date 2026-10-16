<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| F037 AC-1 dan AC-2. Migrasi: database/migrations/2026_10_18_000000_add_status_to_pages_table.php.
| Cabang "visi-misi draf sebelum langkah data" tidak diuji: kolom status baru ada setelah langkah 1,
| sehingga semua baris otomatis terbit (Review #2 catatan 2).
*/

function pagesStatusMigration(): object
{
    return require database_path('migrations/2026_10_18_000000_add_status_to_pages_table.php');
}

/**
 * Keadaan sebelum F037: kolom status dilepas, tiga halaman lama dimasukkan.
 *
 * @param  array<string, string>  $bodies
 */
function pagesBeforeF037(array $bodies = ['profil' => '<p>Profil</p>', 'visi-misi' => '<p>Visi</p>', 'kontak' => '']): void
{
    pagesStatusMigration()->down();
    DB::table('pages')->delete();

    foreach ($bodies as $slug => $body) {
        DB::table('pages')->insert(['slug' => $slug, 'title' => ucfirst($slug), 'body' => $body, 'created_at' => now(), 'updated_at' => now()]);
    }
}

it('AC-1: adds a published status to existing pages and can be reversed', function () {
    pagesBeforeF037();
    expect(Schema::hasColumn('pages', 'status'))->toBeFalse();

    pagesStatusMigration()->up();

    expect(Schema::hasColumn('pages', 'status'))->toBeTrue()
        ->and(DB::table('pages')->pluck('status')->unique()->all())->toBe(['terbit'])
        ->and(DB::table('pages')->orderBy('id')->pluck('title', 'slug')->all())->toBe(['profil' => 'Profil', 'visi-misi' => 'Visi-misi', 'kontak' => 'Kontak'])
        ->and(DB::table('pages')->where('slug', 'profil')->value('body'))->toBe('<p>Profil</p>');

    pagesStatusMigration()->down();

    expect(Schema::hasColumn('pages', 'status'))->toBeFalse()
        ->and(DB::table('pages')->count())->toBe(3);
});

it('AC-2: keeps the vision link in the footer idempotently', function () {
    pagesBeforeF037();
    profilRawSet('portal.footer_links', [['label' => 'Berita', 'url' => '/berita'], ['label' => 'PSB', 'url' => 'https://contoh.test/psb']]);
    $visiId = (int) DB::table('pages')->where('slug', 'visi-misi')->value('id');

    pagesStatusMigration()->up();

    $links = profilRawGet('portal.footer_links');
    expect($links)->toHaveCount(3)
        ->and($links[0])->toBe(['label' => 'Berita', 'url' => '/berita'])
        ->and($links[1])->toBe(['label' => 'PSB', 'url' => 'https://contoh.test/psb'])
        ->and($links[2])->toBe(['label' => null, 'page_id' => $visiId]);

    // Jalankan langkah migrasi sekali lagi tanpa kolom status ganda: lepas kolom saja, data footer dibiarkan.
    Schema::table('pages', fn ($table) => $table->dropColumn('status'));
    pagesStatusMigration()->up();

    expect(profilRawGet('portal.footer_links'))->toHaveCount(3);
});

it('AC-2: adds the vision link even when the page body is empty, and works when footer links are null', function () {
    pagesBeforeF037(['profil' => '', 'visi-misi' => '', 'kontak' => '']);
    $visiId = (int) DB::table('pages')->where('slug', 'visi-misi')->value('id');

    pagesStatusMigration()->up();

    expect(profilRawGet('portal.footer_links'))->toBe([['label' => null, 'page_id' => $visiId]]);
});

it('AC-2: does not add the vision link when there are already eight links and does not throw', function () {
    pagesBeforeF037();
    $eight = array_map(fn (int $i): array => ['label' => "L{$i}", 'url' => '/berita'], range(1, 8));
    profilRawSet('portal.footer_links', $eight);

    pagesStatusMigration()->up();

    expect(profilRawGet('portal.footer_links'))->toBe($eight);
});

it('AC-2: does nothing to the footer when there is no vision page', function () {
    pagesBeforeF037(['profil' => '<p>Profil</p>']);

    pagesStatusMigration()->up();

    expect(DB::table('settings')->where('key', 'portal.footer_links')->exists())->toBeFalse();
});

it('AC-2: down removes page links from the footer and keeps url links', function () {
    pagesBeforeF037();
    pagesStatusMigration()->up();
    $visiId = (int) DB::table('pages')->where('slug', 'visi-misi')->value('id');
    profilRawSet('portal.footer_links', [['label' => 'Berita', 'url' => '/berita'], ['label' => null, 'page_id' => $visiId]]);

    pagesStatusMigration()->down();

    expect(profilRawGet('portal.footer_links'))->toBe([['label' => 'Berita', 'url' => '/berita']])
        ->and(Schema::hasColumn('pages', 'status'))->toBeFalse();
});

it('AC-2: down stores null when only page links were present', function () {
    pagesBeforeF037();
    pagesStatusMigration()->up();
    expect(profilRawGet('portal.footer_links'))->toHaveCount(1);

    pagesStatusMigration()->down();

    expect(profilRawGet('portal.footer_links'))->toBeNull();
});

it('AC-2: down never throws on null or broken footer data', function (mixed $stored) {
    pagesBeforeF037();
    pagesStatusMigration()->up();
    profilRawSet('portal.footer_links', $stored);

    pagesStatusMigration()->down();

    expect(Schema::hasColumn('pages', 'status'))->toBeFalse();
})->with(['null' => [null], 'broken json' => ['RAW:{rusak'], 'scalar' => ['teks']]);
