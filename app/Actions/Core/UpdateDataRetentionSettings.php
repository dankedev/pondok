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
 * dan `retention.letter_snapshot_years` (1–100 tahun, null = tanpa redaksi).
 * Perubahan dicatat di log `sistem` dengan nilai lama dan baru.
 */
class UpdateDataRetentionSettings
{
    public const MAX_YEARS = 100;

    /**
     * @param  array{backup_enabled?: mixed, letter_snapshot_years?: mixed}  $data
     */
    public function handle(array $data, User $by): void
    {
        if (! $by->hasRole('super-admin')) {
            throw new AuthorizationException(__('pondok.data_retention.forbidden'));
        }

        $validated = Validator::make($data, [
            'backup_enabled' => ['required', 'boolean'],
            'letter_snapshot_years' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_YEARS],
        ], attributes: [
            'backup_enabled' => __('pondok.data_retention.backup_enabled'),
            'letter_snapshot_years' => __('pondok.data_retention.letter_snapshot_years'),
        ])->validate();

        $new = [
            'backup_enabled' => (bool) $validated['backup_enabled'],
            'letter_snapshot_years' => filled($validated['letter_snapshot_years'] ?? null) ? (int) $validated['letter_snapshot_years'] : null,
        ];

        if ($new['backup_enabled'] && DB::connection()->getDriverName() === 'pgsql') {
            throw ValidationException::withMessages(['backup_enabled' => __('pondok.backup.pgsql_unsupported')]);
        }

        $old = [
            'backup_enabled' => (bool) Setting::get('backup.enabled', false),
            'letter_snapshot_years' => Setting::get('retention.letter_snapshot_years'),
        ];

        if ($old === $new) {
            return;
        }

        DB::transaction(function () use ($old, $new, $by): void {
            Setting::set('backup.enabled', $new['backup_enabled']);
            Setting::set('retention.letter_snapshot_years', $new['letter_snapshot_years']);

            activity('sistem')
                ->causedBy($by)
                ->event('updated')
                ->withProperties(['old' => $old, 'attributes' => $new])
                ->log(__('pondok.data_retention.activity_updated'));
        });
    }
}
