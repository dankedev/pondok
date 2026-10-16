<?php

namespace App\Console\Commands;

use App\Actions\Core\CheckSystemHealth;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Cek kesehatan server dan instalasi. Exit 1 bila ada pemeriksaan yang gagal.
 */
#[Signature('pondok:doctor')]
#[Description('Memeriksa kesiapan server dan instalasi Pondok')]
class DoctorCommand extends Command
{
    public function handle(CheckSystemHealth $health): int
    {
        $checks = $health->handle();

        $this->table(
            [__('pondok.doctor.columns.check'), __('pondok.doctor.columns.status'), __('pondok.doctor.columns.message')],
            collect($checks)->map(fn (array $check): array => [
                __("pondok.doctor.checks.{$check['key']}"),
                __("pondok.doctor.status.{$check['status']}"),
                $check['message'],
            ])->all(),
        );

        if ($health->hasFailures($checks)) {
            $this->error(__('pondok.doctor.summary_failed'));

            return self::FAILURE;
        }

        $this->info(__('pondok.doctor.summary_ok'));

        return self::SUCCESS;
    }
}
