<?php

namespace App\Actions\Core;

use App\Enums\Module;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Menyalakan/mematikan satu modul fitur (F066). Hanya super-admin. Ketergantungan
 * dijaga dua arah: modul hanya bisa diaktifkan bila semua `requires()` aktif, dan
 * hanya bisa dimatikan bila tidak ada `dependents()` yang aktif. Data modul tidak disentuh.
 */
class SetModuleEnabled
{
    public function handle(User $actor, Module $module, bool $enabled): void
    {
        if (! $actor->hasRole('super-admin')) {
            throw new AuthorizationException(__('pondok.module_settings.errors.forbidden'));
        }

        DB::transaction(function () use ($actor, $module, $enabled): void {
            foreach (Module::cases() as $case) {
                Setting::forget(self::key($case));
            }

            $old = $module->isEnabled();

            if ($old === $enabled) {
                return;
            }

            $enabled ? $this->ensureRequirementsEnabled($module) : $this->ensureNoDependentEnabled($module);

            Setting::set(self::key($module), $enabled);

            activity('pengaturan')
                ->causedBy($actor)
                ->event('updated')
                ->withProperties([
                    'module' => $module->value,
                    'old' => ['enabled' => $old],
                    'attributes' => ['enabled' => $enabled],
                ])
                ->log(__('pondok.module_settings.activity_'.($enabled ? 'enabled' : 'disabled'), ['module' => $module->label()]));
        });
    }

    private function ensureRequirementsEnabled(Module $module): void
    {
        $missing = array_filter($module->requires(), fn (Module $required): bool => ! $required->isEnabled());

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'module' => __('pondok.module_settings.errors.requires_disabled', [
                    'modules' => Module::labels($missing),
                    'module' => $module->label(),
                ]),
            ]);
        }
    }

    private function ensureNoDependentEnabled(Module $module): void
    {
        $active = array_filter($module->dependents(), fn (Module $dependent): bool => $dependent->isEnabled());

        if ($active !== []) {
            throw ValidationException::withMessages([
                'module' => __('pondok.module_settings.errors.dependents_enabled', [
                    'modules' => Module::labels($active),
                    'module' => $module->label(),
                ]),
            ]);
        }
    }

    private static function key(Module $module): string
    {
        return "modules.{$module->value}.enabled";
    }
}
