<?php

namespace App\Models;

use Database\Factories\HomecomingPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Periode pulang massal (F024). Izin PULANG santri mukim dibuat `GenerateHomecomingLeaves`.
 */
#[Fillable(['academic_year_id', 'name', 'depart_on', 'return_on'])]
class HomecomingPeriod extends Model
{
    /** @use HasFactory<HomecomingPeriodFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'depart_on' => 'date',
            'return_on' => 'date',
        ];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }
}
