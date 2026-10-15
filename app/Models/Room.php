<?php

namespace App\Models;

use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kamar asrama. Kapasitas berlaku untuk semua jenjang, jadi hitungan
 * penghuni selalu tanpa cakupan jenjang.
 */
#[Fillable(['dormitory_id', 'name', 'floor', 'capacity', 'is_active'])]
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'floor' => 'integer',
            'capacity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function dormitory(): BelongsTo
    {
        return $this->belongsTo(Dormitory::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(RoomAssignment::class);
    }

    public function activeAssignments(): HasMany
    {
        return $this->assignments()->whereNull('end_on');
    }

    public function supervisors(): HasMany
    {
        return $this->hasMany(DormitorySupervisor::class);
    }

    /**
     * Jumlah penghuni aktif dari semua jenjang.
     */
    public function occupiedCount(): int
    {
        return RoomAssignment::withoutUnitScope()
            ->where('room_id', $this->id)
            ->whereNull('end_on')
            ->count();
    }

    public function remainingCapacity(): int
    {
        return max(0, $this->capacity - $this->occupiedCount());
    }
}
