<?php

namespace App\Actions\Konten\Concerns;

use App\Actions\Konten\StorePublicImage;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Simpan perubahan DB dalam transaksi bersama berkas baru di disk `public`:
 * berkas baru dihapus bila transaksi gagal, berkas lama dihapus setelah commit.
 */
trait ReplacesStoredFiles
{
    /**
     * @template TResult
     *
     * @param  list<string>  $newPaths
     * @param  list<string|null>  $oldPaths
     * @param  Closure(): TResult  $save
     * @return TResult
     */
    protected function saveWithFiles(array $newPaths, array $oldPaths, Closure $save): mixed
    {
        $images = app(StorePublicImage::class);

        try {
            $result = DB::transaction(function () use ($save, $images, $oldPaths): mixed {
                $result = $save();
                DB::afterCommit(fn () => $images->delete(...$oldPaths));

                return $result;
            });
        } catch (Throwable $exception) {
            $images->delete(...$newPaths);

            throw $exception;
        }

        return $result;
    }
}
