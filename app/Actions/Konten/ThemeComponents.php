<?php

namespace App\Actions\Konten;

/**
 * Peta tetap komponen tema portal (F102, skema v1): komponen yang sah per slot dan varian per komponen.
 * Dibatasi pada yang dipakai tiga tema resmi; menambah entri = ubah peta ini dan rilis inti.
 * Nama komponen dari JSON tidak pernah menjadi nama view: renderer hanya memetakan lewat peta ini ke view inti.
 */
class ThemeComponents
{
    /** Komponen sah di `header` dan `footer`; hanya `container` boleh punya `children`. */
    public const SLOTS = [
        'header' => ['container', 'brand', 'nav_menu'],
        'footer' => ['container', 'footer_widgets', 'copyright'],
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

    /** Varian per komponen beranda: `split` = tampilan preset `default`, `centered` = preset `centered`. */
    public const VARIANTS = [
        'hero' => ['split', 'centered'],
        'text_image' => ['split', 'centered'],
        'feature_grid' => ['split', 'centered'],
        'quote' => ['split', 'centered'],
        'cta' => ['split'],
        'video' => ['split'],
        'feed_list' => ['split'],
    ];
}
