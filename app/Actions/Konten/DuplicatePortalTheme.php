<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\WritesInstalledThemes;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Duplikat tema (resmi atau terpasang) menjadi tema buatan sendiri `lokal-<nama>` (F111): `handle(sumber, ['name' => ...], pelaku)`
 * mengembalikan slug baru. Hasilnya tema terpasang biasa versi `1.0.0`: `theme.json` sumber dengan slug dan nama baru, CSS dengan
 * scope `body[data-pt-theme]` diganti ke slug baru (sha256 dan size dihitung ulang), font disalin byte-identik. Lewat staging,
 * validasi penuh, dan `swap` yang sama dengan pemasangan, jadi gagal = folder terpasang tidak berubah. Log `duplicated`.
 */
class DuplicatePortalTheme
{
    use WritesInstalledThemes;

    private const MAX_NAME = 60;

    /** Slug maksimal 40 karakter, awalan `lokal-` memakai 6. */
    private const MAX_SLUG_REMAINDER = 34;

    public function __construct(
        private readonly ValidatePortalTheme $validate,
        private readonly ValidateThemePackage $package,
        private readonly ResolveThemePath $paths,
    ) {}

    /**
     * @param  array<string, mixed>  $input  hanya `name`
     * @return string slug tema baru
     *
     * @throws InvalidArgumentException
     */
    public function handle(string $sourceSlug, array $input, User $actor): string
    {
        $this->authorizeManagement($actor);

        if (($unknown = array_diff(array_keys($input), ['name'])) !== []) {
            $this->reject('unknown_input', ['keys' => Str::limit(implode(', ', $unknown), 60)]);
        }

        $name = $input['name'] ?? null;
        $remainder = is_string($name) ? Str::slug($name) : '';

        if (! is_string($name) || trim($name) === '' || mb_strlen($name) > self::MAX_NAME || $remainder === '' || strlen($remainder) > self::MAX_SLUG_REMAINDER) {
            $this->reject('name_invalid');
        }

        $slug = ResolveThemePath::LOCAL_PREFIX.$remainder;
        $sourcePath = $this->paths->handle($sourceSlug);

        if ($sourcePath === null) {
            $this->reject('source_missing');
        }

        $this->withWriteLock($slug, function () use ($slug, $name, $sourcePath): void {
            if ($this->paths->handle($slug) !== null || file_exists($this->themeRoot()."/{$slug}")) {
                $this->reject('slug_exists');
            }

            $source = $this->validate->handle($sourcePath);
            $raw = json_decode((string) file_get_contents($sourcePath), true);
            $files = isset($source['assets']) ? $this->installedAssetFiles(dirname($sourcePath), $source) : [];

            if (isset($files['theme.css'])) {
                $files['theme.css'] = str_replace($this->scope($source['slug']), $this->scope($slug), $files['theme.css']);
                $raw['assets']['css'] = [...$raw['assets']['css'], 'sha256' => hash('sha256', $files['theme.css']), 'size' => strlen($files['theme.css'])];
            }

            $manifest = $this->validate->handle($this->stage($slug, $this->encodeManifest([...$raw, 'slug' => $slug, 'name' => trim($name), 'version' => '1.0.0'])));

            $this->stageAssets($this->package, $slug, $manifest, $files);
            $this->swap($slug, $manifest);
        });

        activity('konten')
            ->causedBy($actor)
            ->event('duplicated')
            ->withProperties(['slug' => $slug, 'source' => $sourceSlug])
            ->log('portal_theme_duplicated');

        return $slug;
    }
}
