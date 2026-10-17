<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\SavesProfileSettings;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Simpan beranda portal sebagai daftar blok (F045) di `portal.home_blocks`.
 *
 * Seluruh blok divalidasi lewat `NormalizeContentBlocks` sebelum berkas apa pun disimpan.
 * Gambar unggahan disimpan di `portal/blocks`; path lama hanya diterima bila sudah ada di
 * beranda tersimpan, dan berkas yang tidak dipakai lagi dihapus setelah commit. Log
 * `portal_home_updated` hanya bila ada perubahan.
 */
class UpdatePortalHome
{
    use SavesProfileSettings;

    public const KEY = 'portal.home_blocks';

    public const IMAGE_DIRECTORY = 'portal/blocks';

    public function __construct(
        private readonly NormalizeContentBlocks $normalize,
        private readonly StorePublicImage $images,
    ) {}

    /**
     * @param  array{home_blocks?: mixed}  $data
     */
    public function handle(array $data, User $actor): void
    {
        $oldImages = $this->imagePaths(Setting::get(self::KEY));
        $blocks = $this->normalize->handle('home', $data['home_blocks'] ?? [], $oldImages);
        $newPaths = [];

        try {
            foreach ($blocks as $index => $block) {
                $image = $block['data']['image'] ?? null;

                if ($image instanceof UploadedFile) {
                    $path = $this->images->handle($image, self::IMAGE_DIRECTORY, "home_blocks.{$index}.data.image");
                    $newPaths[] = $path;
                    $blocks[$index]['data']['image'] = $path;
                }
            }
        } catch (Throwable $exception) {
            $this->images->delete(...$newPaths);

            throw $exception;
        }

        $oldPaths = array_values(array_diff($oldImages, $this->imagePaths($blocks)));

        $this->saveProfileSettings([self::KEY => $blocks], 'portal_home_updated', $actor, $newPaths, $oldPaths);
    }

    /**
     * Path gambar yang dipakai blok tersimpan.
     *
     * @return list<string>
     */
    private function imagePaths(mixed $blocks): array
    {
        $paths = [];

        foreach (is_array($blocks) ? $blocks : [] as $block) {
            $image = is_array($block) && is_array($block['data'] ?? null) ? ($block['data']['image'] ?? null) : null;

            if (is_string($image) && $image !== '') {
                $paths[] = $image;
            }
        }

        return $paths;
    }
}
