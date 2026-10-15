<?php

namespace App\Models\Concerns;

use App\Models\Scopes\UnitScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pasang ke model yang datanya dibatasi per jenjang, misalnya Student,
 * Invoice, dan Applicant.
 *
 * Secara bawaan menyaring kolom `unit_id`. Model yang jenjangnya ada di
 * kolom lain cukup mengisi `$unitScopeColumn`. Model yang jenjangnya
 * dibaca lewat relasi (mis. Invoice lewat Student) meng-override
 * `applyUnitScope()`.
 */
trait HasUnitScope
{
    public static function bootHasUnitScope(): void
    {
        static::addGlobalScope(new UnitScope);
    }

    /**
     * @param  list<int>  $unitIds
     */
    public function applyUnitScope(Builder $builder, array $unitIds): void
    {
        $builder->whereIn($this->qualifyColumn($this->unitScopeColumn ?? 'unit_id'), $unitIds);
    }

    public static function withoutUnitScope(): Builder
    {
        return static::withoutGlobalScope(UnitScope::class);
    }
}
