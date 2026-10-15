<?php

namespace App\Models;

use App\Models\Concerns\HasDocuments;
use Database\Factories\StaffFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'nip', 'name', 'gender', 'phone', 'position', 'is_active'])]
class Staff extends Model
{
    /** @use HasFactory<StaffFactory> */
    use HasDocuments, HasFactory;

    protected $table = 'staff';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function supervisions(): HasMany
    {
        return $this->hasMany(DormitorySupervisor::class);
    }
}
