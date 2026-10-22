<?php

namespace App\Actions\Core;

use App\Models\Setting;
use Spatie\Activitylog\Models\Activity;

/**
 * Menghapus activity log lama (F076). Masa simpan `retention.activity_log_months`
 * (bulan); kosong atau di bawah batas minimum = tidak ada yang dihapus. Hanya
 * `log_name` di allowlist yang dihapus; log audit (keuangan, sistem, pengaturan,
 * dokumen, psb, default, null, nama lain) tidak pernah disentuh.
 */
class PruneActivityLog
{
    /** @var list<string> */
    public const PRUNABLE_LOG_NAMES = ['akademik', 'konten'];

    public function handle(): int
    {
        $months = Setting::get('retention.activity_log_months');

        if (! is_numeric($months) || (int) $months < UpdateDataRetentionSettings::MIN_ACTIVITY_LOG_MONTHS) {
            return 0;
        }

        $months = (int) $months;
        $deleted = 0;

        Activity::query()
            ->select('id')
            ->whereIn('log_name', self::PRUNABLE_LOG_NAMES)
            ->where('created_at', '<', now()->subMonthsNoOverflow($months))
            ->chunkById(500, function ($rows) use (&$deleted): void {
                $deleted += Activity::query()->whereIn('id', $rows->pluck('id'))->delete();
            });

        if ($deleted > 0) {
            activity('sistem')
                ->event('prune')
                ->withProperties(['deleted' => $deleted, 'months' => $months, 'log_names' => self::PRUNABLE_LOG_NAMES])
                ->log(__('pondok.data_retention.activity_pruned'));
        }

        return $deleted;
    }
}
