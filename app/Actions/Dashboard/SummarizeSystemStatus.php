<?php

namespace App\Actions\Dashboard;

use App\Enums\Module;
use App\Models\AcademicYear;
use App\Models\Setting;
use App\Models\User;

/**
 * Status sistem untuk dashboard (F018): tahun ajaran aktif, saklar modul, dan pengguna aktif.
 * Jumlah modul dihitung dari `App\Enums\Module`; baris saklar lama di luar enum diabaikan.
 */
class SummarizeSystemStatus
{
    /**
     * @return array{active_academic_year: ?string, enabled_module_count: int, module_count: int, active_user_count: int}
     */
    public function handle(): array
    {
        $modules = collect(Module::cases());
        // Isi cache saklar dengan satu query sebelum `isEnabled()` dibaca per modul.
        Setting::getMany($modules->map(fn (Module $module): string => "modules.{$module->value}.enabled")->all());

        return [
            'active_academic_year' => AcademicYear::query()->active()->value('name'),
            'enabled_module_count' => $modules->filter(fn (Module $module): bool => $module->isEnabled())->count(),
            'module_count' => $modules->count(),
            'active_user_count' => User::query()->where('is_active', true)->count(),
        ];
    }
}
