<?php

namespace App\Actions\Konten;

use Illuminate\Support\HtmlString;

/**
 * Peta tetap komponen tema portal (F102, skema v1): komponen yang sah per slot dan varian per komponen.
 * Menambah entri = ubah peta ini dan rilis inti. Nama komponen dari JSON tidak pernah menjadi nama view:
 * renderer hanya memetakan lewat peta ini ke view inti.
 */
class ThemeComponents
{
    private const LAYOUT = ['container', 'columns', 'divider'];

    /** Komponen sah per slot; hanya `container` dan `columns` boleh punya `children`, `blocks` hanya di `home`. */
    public const SLOTS = [
        'header' => [...self::LAYOUT, 'brand', 'nav_menu'],
        'footer' => [...self::LAYOUT, 'footer_widgets', 'copyright'],
        'home' => [...self::LAYOUT, 'hero', 'text_image', 'feature_grid', 'quote', 'cta', 'video', 'feed_list', 'blocks'],
    ];

    /** Tipe blok beranda (`portal.home_blocks`) -> komponen. */
    public const HOME = [
        'hero' => 'hero',
        'text_image' => 'text_image',
        'features' => 'feature_grid',
        'quote' => 'quote',
        'cta' => 'cta',
        'video' => 'video',
        'feed' => 'feed_list',
    ];

    /** Varian per komponen (yang pertama = bawaan); komponen yang tak tercantum tanpa varian. */
    public const VARIANTS = [
        'hero' => ['center', 'split'],
        'feed_list' => ['cards', 'list', 'magazine'],
        'quote' => ['plain', 'framed'],
        'cta' => ['band', 'card'],
        'feature_grid' => ['cards', 'numbered'],
        'blocks' => ['default', 'centered'],
    ];

    /**
     * Kontrak markup tema ber-CSS (F105): komponen -> nama `data-pt-part` yang SELALU muncul pada seed konten lengkap
     * (union semua varian). Bagian opsional (hanya bila datanya ada) ada di `docs/tema-kontrak-markup.md`, bukan di sini.
     * Sumber tunggal: `hooks()` hanya mencetak, tabel dokumen dijaga test.
     */
    public const PARTS = [
        'container' => ['inner'],
        'columns' => ['grid', 'cell'],
        'divider' => ['line', 'diamond'],
        'brand' => ['name'],
        'nav_menu' => ['desktop', 'mobile', 'toggle', 'link'],
        'footer_widgets' => ['widget', 'heading'],
        'hero' => ['eyebrow', 'title', 'text', 'actions', 'media'],
        'text_image' => ['media', 'heading', 'body', 'link'],
        'feature_grid' => ['heading', 'item', 'item-title', 'item-text', 'number'],
        'quote' => ['text', 'source'],
        'cta' => ['heading', 'actions'],
        'video' => ['heading', 'player'],
        'feed_list' => ['head', 'heading', 'see-all', 'list', 'entry', 'lead'],
        'blocks' => ['hero', 'text-image', 'features', 'feed', 'quote', 'video', 'cta'],
    ];

    /** Tema aktif ber-CSS dan lolos pemeriksaan runtime aset (syarat yang sama dengan `<link>` di layout publik). */
    public static function active(): bool
    {
        $theme = app(ResolveActiveTheme::class)->handle();

        return isset($theme['assets']['css'], $theme['asset_hash']);
    }

    /**
     * Atribut kait kontrak untuk satu elemen: ` data-pt="..."`, ` data-pt-variant="..."`, ` data-pt-part="..."` (masing-masing
     * hanya bila diisi, tiap atribut berspasi pendahulu). String kosong tanpa spasi bila tema aktif tidak ber-CSS, sehingga
     * keluaran tema tanpa CSS tidak berubah satu byte pun (golden).
     */
    public static function hooks(?string $component = null, ?string $variant = null, ?string $part = null): HtmlString
    {
        if (! self::active()) {
            return new HtmlString('');
        }

        $html = '';

        foreach (['data-pt' => $component, 'data-pt-variant' => $variant, 'data-pt-part' => $part] as $name => $value) {
            if ($value !== null) {
                $html .= ' '.$name.'="'.e($value).'"';
            }
        }

        return new HtmlString($html);
    }
}
