<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Halaman statis tetap di tabel `pages` (F016): profil, visi-misi, kontak.
 * Hanya bisa diubah; dibuat oleh `StaticPageSeeder`.
 */
#[Fillable(['slug', 'title', 'body'])]
class StaticPage extends Model
{
    protected $table = 'pages';

    public const SLUGS = ['profil', 'visi-misi', 'kontak'];

    public static function findBySlug(string $slug): ?self
    {
        return static::query()->where('slug', $slug)->first();
    }
}
