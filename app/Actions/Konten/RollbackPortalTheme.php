<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\WritesInstalledThemes;
use App\Models\User;
use InvalidArgumentException;

/**
 * Kembalikan tema terpasang ke satu versi sebelumnya (F103, hanya pemegang `manage:themes`): `previous.json` divalidasi lewat
 * staging, di-rename ke `theme.json` (atomik), lalu `previous.json` dibuang. Bila ber-`assets` (F105), folder `assets/<hash12>`-nya
 * harus ada dengan berkas bersize cocok (kalau tidak, ditolak tanpa perubahan), dan setelahnya folder aset selain milik `theme.json`
 * baru dipangkas. Tanpa `previous.json` atau tak valid = ditolak tanpa
 * perubahan. Boleh menurunkan versi (aturan "versi lebih rendah" hanya untuk pasang). Log `portal_theme_rolled_back`.
 */
class RollbackPortalTheme
{
    use WritesInstalledThemes;

    public function __construct(private readonly ValidatePortalTheme $validate, private readonly ValidateThemePackage $package) {}

    /**
     * @throws InvalidArgumentException
     */
    public function handle(string $slug, User $actor): void
    {
        $this->authorizeManagement($actor);
        $slug = $this->assertThemeSlug($slug);

        $version = $this->withWriteLock($slug, function () use ($slug): string {
            $directory = $this->themeRoot()."/{$slug}";
            $previous = "{$directory}/previous.json";

            if (! is_file($previous)) {
                $this->reject('no_previous');
            }

            $bytes = @file_get_contents($previous);

            if ($bytes === false) {
                $this->reject('write_failed');
            }

            $staged = $this->stage($slug, $bytes);
            $manifest = $this->validate->handle($staged);

            if (isset($manifest['assets'])) {
                $this->package->assertInstalled("{$directory}/assets/".$this->assetHash($previous), $manifest);
            }

            if (! @rename($staged, "{$directory}/theme.json")) {
                $this->reject('write_failed');
            }

            @unlink($previous);
            $this->pruneAssets($slug);

            return $manifest['version'];
        });

        activity('konten')
            ->causedBy($actor)
            ->event('rolled_back')
            ->withProperties(['slug' => $slug, 'version' => $version])
            ->log('portal_theme_rolled_back');
    }
}
