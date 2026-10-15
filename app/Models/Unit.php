<?php

namespace App\Models;

use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Auth;

#[Fillable(['code', 'name', 'sort_order'])]
class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use HasFactory;

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * Jenjang yang ada dalam cakupan user yang login. Tanpa user login atau
     * tanpa baris `unit_user`, semua jenjang ikut.
     */
    public function scopeAccessible(Builder $query): void
    {
        $user = Auth::user();
        $unitIds = $user instanceof User ? $user->scopedUnitIds() : [];

        if ($unitIds !== []) {
            $query->whereKey($unitIds);
        }
    }
}
