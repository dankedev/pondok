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

    /** Halaman Info PSB (F038): isi halaman ditambah bagian otomatis dari data PSB, di route `psb.info`. */
    public const TEMPLATE_PSB_INFO = 'psb-info';

    public const TEMPLATES = [self::TEMPLATE_DEFAULT, self::TEMPLATE_CONTACT, self::TEMPLATE_PSB_INFO];

    /** Slug tetap halaman bertemplate `psb-info`; juga cadangan untuk template lain. */
    public const PSB_INFO_SLUG = 'info-psb';

    /**
     * Slug yang tidak boleh dipakai halaman: route portal/panel, berkas umum, dan
     * cadangan fitur (`info-psb` hanya untuk template `psb-info`, `wali` untuk portal wali).
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

    public function isPsbInfo(): bool
    {
        return $this->template === self::TEMPLATE_PSB_INFO;
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
