<?php

namespace App\Models;

use Database\Factories\StaticPageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Halaman statis portal di tabel `pages` (F016, F037). Admin konten bisa membuat
 * dan menghapus halaman mana pun; tiga halaman awal dibuat `StaticPageSeeder` tetapi
 * setara halaman lain. Yang membedakan hanya `template` (F048). Halaman draf tidak
 * tampil di portal. Tanpa soft delete.
 */
#[Fillable(['slug', 'title', 'body', 'status', 'template'])]
class StaticPage extends Model
{
    /** @use HasFactory<StaticPageFactory> */
    use HasFactory;

    protected $table = 'pages';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'terbit';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED];

    public const STATUS_COLORS = [
        self::STATUS_DRAFT => 'gray',
        self::STATUS_PUBLISHED => 'success',
    ];

    public const TEMPLATE_DEFAULT = 'default';

    /** Menampilkan kartu kontak lembaga di bawah isi halaman. */
    public const TEMPLATE_CONTACT = 'contact';

    public const TEMPLATES = [self::TEMPLATE_DEFAULT, self::TEMPLATE_CONTACT];

    /**
     * Slug yang tidak boleh dipakai halaman: route portal/panel, berkas umum, dan
     * cadangan fitur berikutnya (`info-psb` untuk F038, `wali` untuk portal wali).
     */
    public const RESERVED_SLUGS = [
        'admin', 'berita', 'galeri', 'video', 'psb', 'info-psb', 'sitemap', 'robots', 'verifikasi-surat', 'storage',
        'up', 'livewire', 'filament', 'login', 'logout', 'register', 'api', 'wali', 'build', 'vendor',
    ];

    /** Pola slug, sama dengan regex route `/{page}`. */
    public const SLUG_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';

    public const SLUG_MAX_LENGTH = 50;

    public const TITLE_MAX_LENGTH = 255;

    /** Batas jumlah halaman per instalasi, tanpa pengecualian. */
    public const MAX_PAGES = 50;

    public static function findBySlug(string $slug): ?self
    {
        return static::query()->where('slug', $slug)->first();
    }

    public function isContact(): bool
    {
        return $this->template === self::TEMPLATE_CONTACT;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', self::STATUS_PUBLISHED);
    }
}
