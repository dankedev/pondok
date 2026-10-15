<?php

namespace App\Models;

use Database\Factories\DormitoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Asrama putra (`L`) atau putri (`P`). Tidak dibatasi cakupan jenjang.
 */
#[Fillable(['code', 'name', 'gender', 'location', 'is_active'])]
class Dormitory extends Model
{
    /** @use HasFactory<DormitoryFactory> */
    use HasFactory;

    public const GENDERS = ['L', 'P'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function supervisors(): HasMany
    {
        return $this->hasMany(DormitorySupervisor::class);
    }

    public function feeRates(): HasMany
    {
        return $this->hasMany(FeeRate::class);
    }
}
