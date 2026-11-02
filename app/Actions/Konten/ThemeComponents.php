<?php

namespace App\Actions\Konten;

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
}
