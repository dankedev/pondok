<?php

namespace App\Actions\Konten;

/**
 * Peta enum `palette` dan `surface` pada `theme.json` (F104) ke nilai yang dipegang inti; tema hanya menyebut nama.
 *
 * `palette` = hex primer yang diolah `BuildColorScale` (guard kontras tetap berlaku di `GetPortalTheme`); `navy`
 * adalah bawaan inti dan tidak punya entri. `surface` = timpaan `--color-gray-50`/`--color-gray-100`; `putih`
 * adalah bawaan dan tidak punya entri. `--color-white` tidak pernah disentuh.
 */
class ThemePalette
{
    /** @var array<string, string> */
    public const PALETTES = [
        'hijau' => '#1f6b4f',
    ];

    /** @var array<string, array<string, string>> */
    public const SURFACES = [
        'krem' => ['--color-gray-50' => '#fcfbf6', '--color-gray-100' => '#f9f6ee'],
    ];
}
