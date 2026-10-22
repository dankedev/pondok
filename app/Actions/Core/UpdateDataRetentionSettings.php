<?php

namespace App\Actions\Core;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Menyimpan pengaturan Backup & masa simpan (F042, hanya super-admin):
 * `backup.enabled` (backup terjadwal; tidak bisa dinyalakan di PostgreSQL)
 * `retention.letter_snapshot_years` (1–100 tahun, null = tanpa redaksi), dan
 * `retention.activity_log_months` (24–1200 bulan, null = log tidak dihapus, F076).
 * Perubahan dicatat di log `sistem` dengan nilai lama dan baru.
 */
class UpdateDataRetentionSettings
{
    public const MAX_YEARS = 100;

    public const MIN_ACTIVITY_LOG_MONTHS = 24;

    public const MAX_ACTIVITY_LOG_MONTHS = 1200;

    /**
     * @param  array{backup_enabled?: mixed, letter_snapshot_years?: mixed, activity_log_months?: mixed}  $data
     */
    public function handle(array $data, User $by): void
    {
        if (! $by->hasRole('super-admin')) {
            throw new AuthorizationException(__('pondok.data_retention.forbidden'));
        }

        $validated = Validator::make($data, [
            'backup_enabled' => ['required', 'boolean'],
            'letter_snapshot_years' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_YEARS],
            'activity_log_months' => ['nullable', 'integer', 'min:'.self::MIN_ACTIVITY_LOG_MONTHS, 'max:'.self::MAX_ACTIVITY_LOG_MONTHS],
        ], attributes: [
            'backup_enabled' => __('pondok.data_retention.backup_enabled'),
            'letter_snapshot_years' => __('pondok.data_retention.letter_snapshot_years'),
            'activity_log_months' => __('pondok.data_retention.activity_log_months'),
        ])->validate();

        $new = [
            'backup_enabled' => (bool) $validated['backup_enabled'],
            'letter_snapshot_years' => filled($validated['letter_snapshot_years'] ?? null) ? (int) $validated['letter_snapshot_years'] : null,
            'activity_log_months' => filled($validated['activity_log_months'] ?? null) ? (int) $validated['activity_log_months'] : null,
        ];

        if ($new['backup_enabled'] && DB::connection()->getDriverName() === 'pgsql') {
            throw ValidationException::withMessages(['backup_enabled' => __('pondok.backup.pgsql_unsupported')]);
        }

        $old = [
            'backup_enabled' => (bool) Setting::get('backup.enabled', false),
            'letter_snapshot_years' => Setting::get('retention.letter_snapshot_years'),
            'activity_log_months' => is_numeric($months = Setting::get('retention.activity_log_months')) ? (int) $months : null,
        ];

        if ($old === $new) {
            return;
        }

        DB::transaction(function () use ($old, $new, $by): void {
            Setting::set('backup.enabled', $new['backup_enabled']);
            Setting::set('retention.letter_snapshot_years', $new['letter_snapshot_years']);
            Setting::set('retention.activity_log_months', $new['activity_log_months']);

            activity('sistem')
                ->causedBy($by)
                ->event('updated')
                ->withProperties(['old' => $old, 'attributes' => $new])
                ->log(__('pondok.data_retention.activity_updated'));
        });
    }
}
