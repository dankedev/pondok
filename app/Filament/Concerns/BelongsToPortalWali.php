<?php

namespace App\Filament\Concerns;

use App\Enums\Module;
use App\Models\Scopes\UnitScope;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Halaman panel `wali` (F022). `canAccess()` dicek ulang di setiap permintaan Livewire,
 * karena `/livewire/update` tidak melewati middleware modul panel. Data hanya lewat
 * method `portal*()` di User; id disimpan, record di-resolve ulang setiap render.
 */
trait BelongsToPortalWali
{
    public static function getModule(): string
    {
        return Module::PortalWali->value;
    }

    public static function canAccess(): bool
    {
        return Module::PortalWali->isEnabled();
    }

    /**
     * Ringkasan santri portal: jenjang, rombel tahun aktif, dan kamar aktif (bila Asrama aktif),
     * tanpa cakupan jenjang user login.
     *
     * @param  Builder<Student>  $query
     * @return Builder<Student>
     */
    protected static function withPortalSummary(Builder $query): Builder
    {
        $query->with(['unit', 'currentEnrollment.classroom']);

        if (Module::Asrama->isEnabled()) {
            $query->with(['activeRoomAssignment' => fn (Relation $relation) => $relation
                ->withoutGlobalScope(UnitScope::class)
                ->with('room.dormitory')]);
        }

        return $query;
    }
}
