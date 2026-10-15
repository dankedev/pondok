<?php

namespace App\Actions\Dashboard;

use App\Models\AcademicYear;
use App\Models\Setting;
use App\Models\User;

/**
 * Status sistem untuk dashboard (F018): tahun ajaran aktif, saklar modul, dan pengguna aktif.
 * Jumlah modul dihitung dari baris `settings` berpola `modules.%.enabled`.
 */
class SummarizeSystemStatus
{
    /**
     * @return array{active_academic_year: ?string, enabled_module_count: int, module_count: int, active_user_count: int}
     */
    public function handle(): array
    {
        $switches = Setting::query()
            ->where('key', 'like', 'modules.%.enabled')
            ->pluck('value');

        return [
            'active_academic_year' => AcademicYear::query()->active()->value('name'),
            'enabled_module_count' => $switches->filter(fn (mixed $value): bool => (bool) $value)->count(),
            'module_count' => $switches->count(),
            'active_user_count' => User::query()->where('is_active', true)->count(),
        ];
    }
}
