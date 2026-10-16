<?php

namespace App\Actions\Core;

use App\Services\SystemProbe;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Menghubungkan disk `public` ke folder web. Pengganti `storage:link` yang
 * tidak gagal keras bila hosting mematikan `symlink`, dan memberi jalan keluar
 * `PUBLIC_STORAGE_ROOT`.
 */
class LinkPublicStorage
{
    public function __construct(private SystemProbe $probe) {}

    /**
     * @return array{status: 'linked'|'already_linked'|'direct'|'symlink_unavailable'|'conflict'|'failed', link: string, target: string, suggestion: ?string}
     */
    public function handle(): array
    {
        $target = (string) config('filesystems.disks.public.root');
        $link = (string) (array_search($target, (array) config('filesystems.links', []), true) ?: public_path('storage'));
        $suggestion = 'PUBLIC_STORAGE_ROOT='.public_path('storage');

        File::ensureDirectoryExists($target);

        $result = fn (string $status, ?string $suggestion = null): array => [
            'status' => $status, 'link' => $link, 'target' => $target, 'suggestion' => $suggestion,
        ];

        $realTarget = realpath($target);
        $realPublic = realpath(public_path());

        if ($realTarget !== false && $realPublic !== false && str_starts_with($realTarget.'/', rtrim($realPublic, '/').'/')) {
            return $result('direct');
        }

        if (is_link($link)) {
            return realpath($link) === $realTarget ? $result('already_linked') : $result('conflict');
        }

        if (file_exists($link)) {
            return $result('conflict');
        }

        if (! $this->probe->functionEnabled('symlink')) {
            return $result('symlink_unavailable', $suggestion);
        }

        try {
            if (File::link($target, $link) === false) {
                return $result('failed', $suggestion);
            }
        } catch (Throwable) {
            return $result('failed', $suggestion);
        }

        return $result('linked');
    }
}
