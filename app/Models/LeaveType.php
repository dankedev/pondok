<?php

namespace App\Models;

use App\Enums\LeaveApprover;
use Database\Factories\LeaveTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jenis izin santri (F024), mis. KELUAR atau PULANG. Tidak dihapus, cukup dinonaktifkan.
 */
#[Fillable(['code', 'name', 'max_hours', 'approver', 'requires_guardian', 'is_active'])]
class LeaveType extends Model
{
    /** @use HasFactory<LeaveTypeFactory> */
    use HasFactory;

    /** Kode jenis izin yang dipakai pulang massal. */
    public const CODE_HOMECOMING = 'PULANG';

    protected function casts(): array
    {
        return [
            'max_hours' => 'integer',
            'approver' => LeaveApprover::class,
            'requires_guardian' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where($this->qualifyColumn('is_active'), true);
    }
}
