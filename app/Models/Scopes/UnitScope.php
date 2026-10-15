<?php

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Menyaring data berdasarkan cakupan jenjang (`unit_user`) user yang login.
 *
 * Tanpa user login (scheduler, queue, console) atau user tanpa baris di
 * `unit_user`, data tidak disaring.
 */
class UnitScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return;
        }

        $unitIds = $user->scopedUnitIds();

        if ($unitIds === []) {
            return;
        }

        $model->applyUnitScope($builder, $unitIds);
    }
}
