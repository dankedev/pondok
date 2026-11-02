<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\WritesInstalledThemes;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Hapus tema terpasang (F103, hanya pemegang `manage:themes`): folder `<slug>` beserta `previous.json` dan status pasang
 * `themes.install.<slug>`. `theme.template` tidak disentuh (portal jatuh ke chrome inti bila tema aktif hilang).
 * Tema resmi tidak bisa dihapus. Log `portal_theme_removed`.
 */
class RemovePortalTheme
{
    use WritesInstalledThemes;

    public function __construct(private readonly ValidatePortalTheme $validate) {}

    /**
     * @throws InvalidArgumentException
     */
    public function handle(string $slug, User $actor): void
    {
        $this->authorizeManagement($actor);
        $slug = $this->assertThemeSlug($slug);

        $version = $this->withWriteLock($slug, function () use ($slug): ?string {
            $directory = $this->themeRoot()."/{$slug}";

            try {
                $version = is_file("{$directory}/theme.json") ? $this->validate->handle("{$directory}/theme.json")['version'] : null;
            } catch (InvalidArgumentException) {
                $version = null;
            }

            File::deleteDirectory($directory);
            Setting::query()->whereKey("themes.install.{$slug}")->get()->each->delete();

            return $version;
        });

        activity('konten')
            ->causedBy($actor)
            ->event('removed')
            ->withProperties(['slug' => $slug, 'version' => $version])
            ->log('portal_theme_removed');
    }
}
