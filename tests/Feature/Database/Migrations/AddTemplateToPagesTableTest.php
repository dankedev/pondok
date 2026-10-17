<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| F048 AC-1. Migrasi: database/migrations/2026_10_21_000000_add_template_to_pages_table.php.
*/

function pagesTemplateMigration(): object
{
    return require database_path('migrations/2026_10_21_000000_add_template_to_pages_table.php');
}

/**
 * Keadaan sebelum F048: kolom template dilepas, tiga halaman awal dan satu halaman bebas dimasukkan.
 */
function pagesBeforeF048(): void
{
    if (Schema::hasColumn('pages', 'template')) {
        Schema::table('pages', fn ($table) => $table->dropColumn('template'));
    }

    DB::table('pages')->delete();

    foreach (['profil', 'visi-misi', 'kontak', 'pengurus'] as $slug) {
        DB::table('pages')->insert(['slug' => $slug, 'title' => ucfirst($slug), 'body' => '', 'status' => 'terbit', 'created_at' => now(), 'updated_at' => now()]);
    }
}

it('AC-1: adds the template column, marks only kontak as contact, keeps slugs and rows, and can be reversed', function () {
    pagesBeforeF048();
    $ids = DB::table('pages')->orderBy('id')->pluck('slug', 'id')->all();

    pagesTemplateMigration()->up();

    expect(Schema::hasColumn('pages', 'template'))->toBeTrue()
        ->and(DB::table('pages')->pluck('template', 'slug')->all())->toBe([
            'profil' => 'default', 'visi-misi' => 'default', 'kontak' => 'contact', 'pengurus' => 'default',
        ])
        ->and(DB::table('pages')->orderBy('id')->pluck('slug', 'id')->all())->toBe($ids);

    pagesTemplateMigration()->down();

    expect(Schema::hasColumn('pages', 'template'))->toBeFalse()
        ->and(DB::table('pages')->count())->toBe(4);
});
