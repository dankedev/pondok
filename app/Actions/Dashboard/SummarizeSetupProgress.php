<?php

namespace App\Actions\Dashboard;

use App\Actions\Core\CheckSystemHealth;
use App\Actions\Konten\GetSchoolProfile;
use App\Enums\Module;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\FeeRate;
use App\Models\Student;
use App\Models\Unit;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;

/**
 * Progres setup awal (F094) untuk widget dashboard super-admin. Status setiap langkah
 * dihitung dari data nyata; langkah milik modul mati atau yang tabelnya belum ada tidak
 * ikut dihitung. Email dan cron/antrean memakai pemeriksaan `pondok:doctor`.
 */
class SummarizeSetupProgress
{
    public function __construct(private CheckSystemHealth $health) {}

    /**
     * @return array{steps: list<array{key: string, done: bool}>, done_count: int, total: int, percent: int}
     */
    public function handle(): array
    {
        $steps = [];

        foreach ($this->definitions() as $key => [$module, $tables, $isDone]) {
            if ($module !== null && ! $module->isEnabled()) {
                continue;
            }

            foreach ($tables as $table) {
                if (! Schema::hasTable($table)) {
                    continue 2;
                }
            }

            $steps[] = ['key' => $key, 'done' => (bool) $isDone()];
        }

        $total = count($steps);
        $doneCount = count(array_filter($steps, fn (array $step): bool => $step['done']));

        return [
            'steps' => $steps,
            'done_count' => $doneCount,
            'total' => $total,
            'percent' => $total === 0 ? 100 : intdiv($doneCount * 100, $total),
        ];
    }

    /**
     * Langkah berurutan: key => [modul (NULL = inti), tabel yang dibaca, cek selesai].
     *
     * @return array<string, array{0: ?Module, 1: list<string>, 2: Closure(): bool}>
     */
    private function definitions(): array
    {
        return [
            'profile' => [null, ['settings'], function (): bool {
                $profile = app(GetSchoolProfile::class)->handle();

                return filled($profile['name']) && filled($profile['logo_path']) && GetSchoolProfile::hasContactData($profile);
            }],
            'units' => [null, ['units'], fn (): bool => Unit::query()->exists()],
            'academic_year' => [null, ['academic_years'], fn (): bool => AcademicYear::current() !== null],
            // Semua modul aktif sejak instalasi; dianggap ditinjau bila pernah diubah lewat Pengaturan → Modul.
            'modules' => [null, ['settings', 'activity_log'], fn (): bool => collect(Module::cases())->contains(fn (Module $module): bool => ! $module->isEnabled())
                || Activity::query()->inLog('pengaturan')->whereNotNull('properties->module')->exists()],
            'classrooms' => [Module::Akademik, ['classrooms'], fn (): bool => Classroom::query()->exists()],
            'students' => [Module::Akademik, ['students'], fn (): bool => Student::query()->exists()],
            'staff' => [null, ['users', 'roles'], fn (): bool => User::query()
                ->whereHas('roles', fn ($query) => $query->whereNotIn('name', ['super-admin', 'wali']))
                ->exists()],
            'fee_rates' => [Module::Keuangan, ['fee_rates'], fn (): bool => FeeRate::query()->exists()],
            'mail' => [null, [], fn (): bool => $this->healthOk(['mail'])],
            'cron' => [null, ['jobs', 'failed_jobs'], fn (): bool => $this->healthOk(['scheduler', 'queue'])],
        ];
    }

    /**
     * @param  list<string>  $keys
     */
    private function healthOk(array $keys): bool
    {
        return collect($this->health->only($keys))->every(fn (array $check): bool => $check['status'] === 'ok');
    }
}
