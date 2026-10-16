<?php

namespace App\Console\Commands;

use App\Actions\Core\PublishPublicDirectory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Menyalin isi `public/` ke document root lain (mis. `public_html`) bila
 * document root domain tidak bisa diarahkan ke folder `public` aplikasi.
 */
#[Signature('pondok:publish-public {--path= : Folder tujuan, bawaan PONDOK_PUBLIC_PATH} {--force : Timpa index.php yang bukan buatan Pondok}')]
#[Description('Menyalin folder public ke public_html')]
class PublishPublicCommand extends Command
{
    public function handle(PublishPublicDirectory $publish): int
    {
        $target = (string) ($this->option('path') ?: config('pondok.public_path'));

        if (trim($target) === '') {
            $this->error(__('pondok.publish_public.path_required'));

            return self::FAILURE;
        }

        try {
            $result = $publish->handle(base_path('public'), rtrim($target, '/'), base_path(), (bool) $this->option('force'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(__('pondok.publish_public.done', ['count' => $result['copied'], 'path' => $target]));

        return self::SUCCESS;
    }
}
