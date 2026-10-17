<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * F045: pindahkan beranda F036 (field hero, keunggulan, media, ringkasan halaman `profil`, seksi
 * otomatis) dan kutipan arsip F043 ke daftar blok `portal.home_blocks` (data saja).
 *
 * Urutan blok sama dengan beranda lama: hero (tombol Daftar + Berita), keunggulan, video profil,
 * video PSB, salinan statis ringkasan profil, feed berita/prestasi/video/galeri, lalu kutipan.
 * Rujukan video/album yang sudah hilang tidak disalin. Bentuk data sama persis dengan hasil
 * `NormalizeContentBlocks` (disalin di sini, tidak memanggil kelas aplikasi), jadi menyimpan
 * ulang tidak mengubah apa pun.
 *
 * Dilewati bila `portal.home_blocks` sudah ada (idempoten) atau DB benar-benar kosong (instalasi
 * baru). Key lama hanya dihapus bila migrasi menulis blok. `down()` hanya menghapus
 * `portal.home_blocks`; isi key lama tidak dipulihkan dan berkas gambar tidak dihapus.
 */
return new class extends Migration
{
    private const LEGACY_KEYS = [
        'portal.hero_greeting',
        'portal.hero_title',
        'portal.hero_subtitle',
        'portal.highlights_heading',
        'portal.highlights',
        'portal.hero_album_id',
        'portal.profile_video_id',
        'portal.psb_video_id',
        'portal.archived_footer_quote',
    ];

    /** Pengodean teks `SanitizeBlockText` (Symfony, tanpa `=`), agar ringkasan tidak berubah saat disimpan ulang. */
    private const ENTITY_REPLACEMENTS = ['&quot;' => '&#34;', '+' => '&#43;', '@' => '&#64;', '`' => '&#96;'];

    private const MAX_FEATURES = 8;

    public function up(): void
    {
        if ($this->has('portal.home_blocks') || ! $this->isExistingInstall()) {
            return;
        }

        $blocks = [$this->block('hero', [
            'eyebrow' => $this->text($this->read('portal.hero_greeting')),
            'title' => $this->text($this->read('portal.hero_title')),
            'text' => $this->text($this->read('portal.hero_subtitle')) ?? $this->text($this->read('school.tagline')),
            'image' => null,
            'image_alt' => null,
            'album_id' => $this->existingId('albums', $this->read('portal.hero_album_id')),
            'primary' => ['type' => 'psb.register', 'label' => null],
            'secondary' => ['type' => 'posts', 'label' => null],
        ])];

        $features = $this->features($this->read('portal.highlights'));

        if ($features !== []) {
            $blocks[] = $this->block('features', ['heading' => $this->text($this->read('portal.highlights_heading')), 'items' => $features]);
        }

        foreach (['portal.profile_video_id', 'portal.psb_video_id'] as $key) {
            $videoId = $this->existingId('videos', $this->read($key));

            if ($videoId !== null) {
                $blocks[] = $this->block('video', ['heading' => null, 'video_id' => $videoId]);
            }
        }

        $profile = $this->profileBlock();

        if ($profile !== null) {
            $blocks[] = $profile;
        }

        foreach (['posts', 'achievements', 'videos', 'albums'] as $source) {
            $blocks[] = $this->block('feed', ['source' => $source, 'heading' => null]);
        }

        $quote = $this->quoteBlock($this->read('portal.archived_footer_quote'));

        if ($quote !== null) {
            $blocks[] = $quote;
        }

        DB::transaction(function () use ($blocks): void {
            $now = now();
            DB::table('settings')->insert([
                'key' => 'portal.home_blocks',
                'value' => json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('settings')->whereIn('key', self::LEGACY_KEYS)->delete();
        });
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'portal.home_blocks')->delete();
    }

    private function isExistingInstall(): bool
    {
        foreach (['settings', 'pages', 'posts', 'albums', 'videos'] as $table) {
            if (DB::table($table)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{type: string, visible: bool, data: array<string, mixed>}
     */
    private function block(string $type, array $data): array
    {
        return ['type' => $type, 'visible' => true, 'data' => $data];
    }

    /**
     * @return list<array{title: string, text: ?string}>
     */
    private function features(mixed $value): array
    {
        $items = [];

        foreach (is_array($value) ? $value : [] as $row) {
            $title = is_array($row) ? $this->text($row['title'] ?? null) : null;

            if ($title !== null && count($items) < self::MAX_FEATURES) {
                $items[] = ['title' => $title, 'text' => $this->text($row['description'] ?? null)];
            }
        }

        return $items;
    }

    /**
     * Salinan statis ringkasan halaman `profil` terbit (logika `SummarizeHtml` lama, 300 karakter).
     *
     * @return ?array<string, mixed>
     */
    private function profileBlock(): ?array
    {
        $page = DB::table('pages')->where('slug', 'profil')->where('status', 'terbit')->first(['id', 'title', 'body']);

        if ($page === null) {
            return null;
        }

        $html = (string) preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', (string) $page->body);
        $html = (string) preg_replace('/<\/?(?:p|div|br|li|h[1-6]|tr|td|th|blockquote|section|article|figcaption|dd|dt)\b[^>]*>/i', ' $0 ', $html);
        $summary = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $summary = Str::limit(trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $summary)), 300, '…');

        if ($summary === '') {
            return null;
        }

        return $this->block('text_image', [
            'heading' => $this->text(mb_substr((string) $page->title, 0, 100)),
            'text' => '<p>'.strtr(htmlspecialchars($summary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), self::ENTITY_REPLACEMENTS).'</p>',
            'image' => null,
            'image_alt' => null,
            'button' => ['type' => 'page', 'label' => null, 'page_id' => (int) $page->id],
        ]);
    }

    /**
     * Kutipan arsip F043 `{arabic, translation, source}`: teks Arab → kutipan kanan-ke-kiri dengan
     * terjemah; tanpa teks Arab → terjemah menjadi teks utama.
     *
     * @return ?array<string, mixed>
     */
    private function quoteBlock(mixed $quote): ?array
    {
        if (! is_array($quote)) {
            return null;
        }

        $arabic = $this->text($quote['arabic'] ?? null);
        $translation = $this->text($quote['translation'] ?? null);
        $text = $arabic ?? $translation;

        if ($text === null) {
            return null;
        }

        return $this->block('quote', [
            'text' => $text,
            'translation' => $arabic === null ? null : $translation,
            'source' => $this->text($quote['source'] ?? null),
            'rtl' => $arabic !== null,
        ]);
    }

    private function existingId(string $table, mixed $value): ?int
    {
        $id = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;

        return $id === false || $id < 1 || ! DB::table($table)->where('id', $id)->exists() ? null : $id;
    }

    private function read(string $key): mixed
    {
        $value = DB::table('settings')->where('key', $key)->value('value');

        return is_string($value) ? json_decode($value, true) : null;
    }

    private function has(string $key): bool
    {
        return DB::table('settings')->where('key', $key)->exists();
    }

    private function text(mixed $value): ?string
    {
        return is_scalar($value) && ! is_bool($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
};
